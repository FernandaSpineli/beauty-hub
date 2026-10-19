<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;

beforeEach(function (): void {
    Tenant::factory()->create(['slug' => 'bella', 'min_shelf_life_days' => 30]);
    asStore(Tenant::query()->firstOrFail(), function (): void {
        $lipstick = Product::factory()->create(['name' => 'Batom', 'slug' => 'batom']);
        $plenty = ProductVariant::factory()->create(['product_id' => $lipstick->id, 'name' => 'Nude', 'position' => 1]);
        $few = ProductVariant::factory()->create(['product_id' => $lipstick->id, 'name' => 'Vinho', 'position' => 2]);
        $expiring = ProductVariant::factory()->create(['product_id' => $lipstick->id, 'name' => 'Coral', 'position' => 3]);
        StockLot::factory()->create(['variant_id' => $plenty->id, 'quantity_on_hand' => 40, 'expires_on' => '2028-03-15']);
        StockLot::factory()->create(['variant_id' => $plenty->id, 'quantity_on_hand' => 5, 'expires_on' => today()->addMonths(5)]);
        StockLot::factory()->create(['variant_id' => $few->id, 'quantity_on_hand' => 2]);
        // Only units inside the minimum shelf life: not for sale.
        StockLot::factory()->create(['variant_id' => $expiring->id, 'quantity_on_hand' => 30, 'expires_on' => today()->addDays(5)]);

        $soldOut = Product::factory()->create(['name' => 'Esgotado', 'slug' => 'esgotado']);
        ProductVariant::factory()->create(['product_id' => $soldOut->id]);
    });
});

it('tells shoppers what they can buy and the expiry month they will get', function (): void {
    $variants = $this->getJson($this->storeUrl('bella', 'products/batom'))->assertOk()->json('data.variants');

    expect(array_map(fn (array $v): array => [$v['name'], $v['in_stock'], $v['low_stock'], $v['ships_with_expiry']], $variants))
        ->toBe([
            ['Nude', true, false, today()->addMonths(5)->format('Y-m')],
            ['Vinho', true, true, today()->addYear()->format('Y-m')],
            ['Coral', false, false, null],
        ])
        ->and($variants[0])->not->toHaveKey('available');
});

it('flags products with nothing to sell and can hide them', function (): void {
    $all = collect($this->getJson($this->storeUrl('bella', 'products?sort=name'))->json('data'))
        ->mapWithKeys(fn (array $p): array => [$p['name'] => $p['in_stock']]);
    expect($all->all())->toBe(['Batom' => true, 'Esgotado' => false]);

    $inStock = $this->getJson($this->storeUrl('bella', 'products?in_stock=1'))->json('data');
    expect(array_column($inStock, 'name'))->toBe(['Batom']);
});
