<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use App\Inventory\Allocation;
use App\Inventory\StockAllocator;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['min_shelf_life_days' => 30]);
    app(TenantContext::class)->set($this->store);

    $this->allocator = app(StockAllocator::class);
    $this->lipstick = ProductVariant::factory()->create();
    $this->lots = [
        'too_close' => StockLot::factory()->create(['variant_id' => $this->lipstick->id, 'lot_code' => 'A', 'quantity_on_hand' => 50, 'expires_on' => today()->addDays(10)]),
        'later' => StockLot::factory()->create(['variant_id' => $this->lipstick->id, 'lot_code' => 'C', 'quantity_on_hand' => 10, 'expires_on' => today()->addMonths(12)]),
        'sooner' => StockLot::factory()->create(['variant_id' => $this->lipstick->id, 'lot_code' => 'B', 'quantity_on_hand' => 3, 'expires_on' => today()->addMonths(4)]),
    ];
});

afterEach(fn () => app(TenantContext::class)->clear());

function reserve(array $quantities): array
{
    return DB::transaction(fn () => test()->allocator->reserve($quantities, 'order:test'));
}

function lotState(StockLot $lot): array
{
    $lot->refresh();

    return [$lot->quantity_on_hand, $lot->quantity_reserved];
}

it('takes the first-expiring sellable lot first, then the next', function (): void {
    $allocations = reserve([$this->lipstick->id => 5]);

    expect(array_map(fn (Allocation $a): array => [$a->lotId, $a->quantity], $allocations))->toBe([
        [$this->lots['sooner']->id, 3],
        [$this->lots['later']->id, 2],
    ])
        ->and(lotState($this->lots['sooner']))->toBe([3, 3])
        ->and(lotState($this->lots['later']))->toBe([10, 2])
        ->and(lotState($this->lots['too_close']))->toBe([50, 0]);
});

it('reserves nothing when any item is short, and says what is missing', function (): void {
    $serum = ProductVariant::factory()->create();
    StockLot::factory()->create(['variant_id' => $serum->id, 'quantity_on_hand' => 5]);

    try {
        reserve([$serum->id => 2, $this->lipstick->id => 14]);
        $this->fail('Expected INSUFFICIENT_STOCK');
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe('INSUFFICIENT_STOCK')
            ->and($e->details)->toBe([['variant_id' => $this->lipstick->id, 'requested' => 14, 'available' => 13]]);
    }

    expect(StockLot::query()->sum('quantity_reserved'))->toBe(0)
        ->and(StockMovement::query()->where('kind', 'reserved')->count())->toBe(0);
});

it('reserves a kit from its components, merged with direct orders of them', function (): void {
    $kit = ProductVariant::factory()->create();
    DB::table('kit_components')->insert(['kit_variant_id' => $kit->id, 'component_variant_id' => $this->lipstick->id, 'quantity' => 2]);

    // 2 kits x 2 lipsticks + 1 lipstick on its own = 5 lipsticks.
    $allocations = reserve([$kit->id => 2, $this->lipstick->id => 1]);

    expect(collect($allocations)->where('forVariantId', $kit->id)->sum('quantity'))->toBe(4)
        ->and(collect($allocations)->where('forVariantId', $this->lipstick->id)->sum('quantity'))->toBe(1)
        ->and(collect($allocations)->pluck('variantId')->unique()->all())->toBe([$this->lipstick->id])
        ->and(StockLot::query()->sum('quantity_reserved'))->toBe(5);
});

it('reports a short kit by its own availability', function (): void {
    $kit = ProductVariant::factory()->create();
    DB::table('kit_components')->insert(['kit_variant_id' => $kit->id, 'component_variant_id' => $this->lipstick->id, 'quantity' => 2]);

    expect(fn () => reserve([$kit->id => 7]))->toThrow(function (ApiException $e) use ($kit): void {
        expect($e->details)->toBe([['variant_id' => $kit->id, 'requested' => 7, 'available' => 6]]);
    });
});

it('releases reservations and sells them, writing the ledger', function (): void {
    $allocations = reserve([$this->lipstick->id => 4]);

    DB::transaction(fn () => $this->allocator->release([$allocations[0]], 'order:test'));
    expect(lotState($this->lots['sooner']))->toBe([3, 0]);

    DB::transaction(fn () => $this->allocator->sell([$allocations[1]], 'order:test'));
    expect(lotState($this->lots['later']))->toBe([9, 0]);

    $ledger = fn (StockLot $lot): array => StockMovement::query()->where('lot_id', $lot->id)->get()
        ->map(fn ($m): array => [$m->kind->value, $m->on_hand_delta, $m->reserved_delta])
        ->sortBy(fn (array $m): string => $m[0])->values()->all();

    expect($ledger($this->lots['sooner']))->toBe([['released', 0, -3], ['reserved', 0, 3]])
        ->and($ledger($this->lots['later']))->toBe([['reserved', 0, 1], ['sold', -1, -1]]);
});
