<?php

declare(strict_types=1);

use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    [$this->kit, $this->serum, $this->cream] = asStore($this->store, function (): array {
        $serum = ProductVariant::factory()->create(['name' => 'Sérum']);
        $cream = ProductVariant::factory()->create(['name' => 'Creme']);
        StockLot::factory()->create(['variant_id' => $serum->id, 'quantity_on_hand' => 7, 'expires_on' => today()->addMonths(10)]);
        StockLot::factory()->create(['variant_id' => $cream->id, 'quantity_on_hand' => 9, 'expires_on' => today()->addMonths(6)]);

        return [ProductVariant::factory()->create(['name' => 'Kit Noite']), $serum, $cream];
    });
    $this->withToken(tokenFor($this->store));
    $this->kitUrl = fn (ProductVariant $v): string => $this->storeUrl('bella', "admin/variants/{$v->id}/components");
});

function defineKit(ProductVariant $kit, array $components): TestResponse
{
    return test()->putJson((test()->kitUrl)($kit), ['components' => $components]);
}

it('derives kit availability from its components', function (): void {
    // 7 serums / 1 and 9 creams / 2 per kit: the cream runs out first.
    defineKit($this->kit, [
        ['variant_id' => $this->serum->id, 'quantity' => 1],
        ['variant_id' => $this->cream->id, 'quantity' => 2],
    ])->assertOk()
        ->assertJsonPath('data.available', 4)
        ->assertJsonCount(2, 'data.components');

    // The kit ships with the earliest expiry of its components.
    $this->getJson($this->storeUrl('bella', "admin/variants/{$this->kit->id}/stock"))
        ->assertJsonPath('data.available', 4)
        ->assertJsonPath('data.next_expiry', today()->addMonths(6)->toDateString())
        ->assertJsonCount(0, 'data.lots');
});

it('is out of stock when a component is', function (): void {
    $empty = asStore($this->store, fn () => ProductVariant::factory()->create());

    defineKit($this->kit, [
        ['variant_id' => $this->serum->id, 'quantity' => 1],
        ['variant_id' => $empty->id, 'quantity' => 1],
    ])->assertOk()->assertJsonPath('data.available', 0);
});

it('turns back into a regular variant with an empty list', function (): void {
    defineKit($this->kit, [['variant_id' => $this->serum->id, 'quantity' => 1]]);

    defineKit($this->kit, [])->assertOk()->assertJsonCount(0, 'data.components')->assertJsonPath('data.available', 0);
});

it('does not receive stock for a kit', function (): void {
    defineKit($this->kit, [['variant_id' => $this->serum->id, 'quantity' => 1]]);

    $this->postJson($this->storeUrl('bella', "admin/variants/{$this->kit->id}/lots"), [
        'lot_code' => 'K1', 'expires_on' => today()->addYear()->toDateString(), 'quantity' => 5,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'KIT_HAS_NO_STOCK');
});

it('refuses nesting, self-reference and variants holding stock', function (): void {
    defineKit($this->kit, [['variant_id' => $this->serum->id, 'quantity' => 1]]);
    $bigKit = asStore($this->store, fn () => ProductVariant::factory()->create());

    defineKit($bigKit, [['variant_id' => $this->kit->id, 'quantity' => 1]])
        ->assertUnprocessable()->assertJsonPath('error.code', 'KIT_NESTING');
    defineKit($this->serum, [['variant_id' => $this->cream->id, 'quantity' => 1]])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VARIANT_HAS_STOCK');
    defineKit($bigKit, [['variant_id' => $bigKit->id, 'quantity' => 1]])
        ->assertUnprocessable()->assertJsonPath('error.details.0.field', 'components.0.variant_id');
});

it('only accepts the store\'s own variants as components', function (): void {
    $foreign = asStore(Tenant::factory()->create(), fn () => ProductVariant::factory()->create());

    defineKit($this->kit, [['variant_id' => $foreign->id, 'quantity' => 1]])
        ->assertUnprocessable()->assertJsonPath('error.details.0.field', 'components.0.variant_id');
});
