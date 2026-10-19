<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\VariantStock;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella', 'min_shelf_life_days' => 30]);
    $this->variant = asStore($this->store, fn () => ProductVariant::factory()->create());
    $this->withToken(tokenFor($this->store));
    $this->url = fn (string $path): string => $this->storeUrl('bella', 'admin/'.$path);
});

function receive(string $lot, string $expires, int $quantity): TestResponse
{
    return test()->postJson((test()->url)('variants/'.test()->variant->id.'/lots'), [
        'lot_code' => $lot, 'expires_on' => $expires, 'quantity' => $quantity,
    ]);
}

it('receives lots and records them in the ledger', function (): void {
    receive('L2401', today()->addYear()->toDateString(), 12)
        ->assertCreated()
        ->assertJsonPath('data.on_hand', 12)
        ->assertJsonPath('data.sellable', true);

    receive('L2401', today()->addYear()->toDateString(), 3)->assertCreated()->assertJsonPath('data.on_hand', 15);

    $moves = asStore($this->store, fn () => StockMovement::query()->orderBy('created_at')->pluck('on_hand_delta')->all());
    expect($moves)->toBe([12, 3]);
});

it('refuses a known lot code with a different expiry, or an expired one', function (): void {
    receive('L2401', today()->addYear()->toDateString(), 5);

    receive('L2401', today()->addYears(2)->toDateString(), 5)
        ->assertUnprocessable()->assertJsonPath('error.code', 'LOT_EXPIRY_MISMATCH');
    receive('L9999', today()->toDateString(), 5)
        ->assertUnprocessable()->assertJsonPath('error.details.0.field', 'expires_on');
});

it('only counts lots that clear the store\'s minimum shelf life', function (): void {
    receive('SOON', today()->addDays(20)->toDateString(), 50);
    receive('LATER', today()->addMonths(8)->toDateString(), 7);
    receive('LATEST', today()->addMonths(14)->toDateString(), 4);

    $this->getJson(($this->url)("variants/{$this->variant->id}/stock"))
        ->assertOk()
        ->assertJsonPath('data.available', 11)
        ->assertJsonPath('data.next_expiry', today()->addMonths(8)->toDateString())
        ->assertJsonPath('data.lots.0.lot_code', 'SOON')
        ->assertJsonPath('data.lots.0.sellable', false);

    // A store that accepts shorter shelf lives sells the first lot too.
    $this->store->update(['min_shelf_life_days' => 10]);
    $this->getJson(($this->url)("variants/{$this->variant->id}/stock"))->assertJsonPath('data.available', 61);
});

it('adjusts stock but never below the reserved units', function (): void {
    $lot = receive('L1', today()->addYear()->toDateString(), 10)->json('data');
    asStore($this->store, fn () => StockLot::query()->whereKey($lot['id'])->update(['quantity_reserved' => 4]));

    $this->postJson(($this->url)("lots/{$lot['id']}/adjustments"), ['delta' => -6, 'reason' => 'damaged', 'note' => 'caixa molhada'])
        ->assertOk()->assertJsonPath('data.on_hand', 4);
    $this->postJson(($this->url)("lots/{$lot['id']}/adjustments"), ['delta' => -1, 'reason' => 'count_correction'])
        ->assertConflict()->assertJsonPath('error.code', 'STOCK_RESERVED');

    $reasons = asStore($this->store, fn () => StockMovement::query()->whereNotNull('reason')->pluck('reason')->all());
    expect($reasons)->toBe(['damaged caixa molhada']);
});

it('validates adjustments', function (array $payload): void {
    $lot = receive('L1', today()->addYear()->toDateString(), 10)->json('data');

    $this->postJson(($this->url)("lots/{$lot['id']}/adjustments"), $payload)->assertUnprocessable();
})->with([
    'zero' => [['delta' => 0, 'reason' => 'other']],
    'unknown reason' => [['delta' => -1, 'reason' => 'stolen']],
]);

it('reports lots with units left that expire soon', function (): void {
    receive('SOON', today()->addDays(20)->toDateString(), 5);
    receive('MID', today()->addDays(50)->toDateString(), 5);
    receive('LATER', today()->addYear()->toDateString(), 5);

    $this->getJson(($this->url)('stock/expiring?within_days=60'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.lot_code', 'SOON')
        ->assertJsonPath('data.0.sellable', false)
        ->assertJsonPath('data.1.sellable', true)
        ->assertJsonPath('data.0.variant.sku', $this->variant->sku);
});

it('keeps stock figures inside the store, view included', function (): void {
    asStore($this->store, fn () => StockLot::factory()->create(['variant_id' => $this->variant->id]));
    $glow = Tenant::factory()->create();

    expect(asStore($glow, fn () => VariantStock::query()->withoutGlobalScopes()->count()))->toBe(0)
        ->and(asStore($this->store, fn () => VariantStock::query()->count()))->toBe(1);
});
