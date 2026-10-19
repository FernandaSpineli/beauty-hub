<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    [$this->lipstick, $this->serum] = asStore($this->store, function (): array {
        $lipstick = ProductVariant::factory()->create(['price_cents' => 2990, 'name' => 'Nude']);
        $serum = ProductVariant::factory()->create(['price_cents' => 11990, 'name' => 'Sérum']);
        StockLot::factory()->create(['variant_id' => $lipstick->id, 'quantity_on_hand' => 30]);
        StockLot::factory()->create(['variant_id' => $serum->id, 'quantity_on_hand' => 2]);

        return [$lipstick, $serum];
    });
    $this->withToken(tokenFor($this->store, UserRole::Customer));
    $this->cart = fn (string $path = ''): string => $this->storeUrl('bella', 'cart'.$path);
});

function add(ProductVariant $variant, int $quantity = 1): TestResponse
{
    return test()->postJson((test()->cart)('/items'), ['variant_id' => $variant->id, 'quantity' => $quantity]);
}

it('prices the cart with current prices', function (): void {
    add($this->lipstick, 2)->assertOk();
    add($this->serum)->assertOk()
        ->assertJsonPath('data.items.0.line_total_cents', 5980)
        ->assertJsonPath('data.items.1.name', 'Sérum')
        ->assertJsonPath('data.subtotal_cents', 17970)
        ->assertJsonPath('data.total_cents', 17970)
        ->assertJsonPath('data.can_checkout', true);

    asStore($this->store, fn () => $this->lipstick->update(['price_cents' => 3490]));
    $this->getJson(($this->cart)())->assertJsonPath('data.subtotal_cents', 18970);
});

it('adds to an existing line, up to the per-item limit', function (): void {
    add($this->lipstick, 15);
    add($this->lipstick, 15)->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.quantity', 20);
});

it('refuses more units than are in stock', function (): void {
    add($this->serum, 3)->assertConflict()
        ->assertJsonPath('error.code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('error.details.0.available', 2);

    add($this->serum, 2)->assertOk();
    $this->patchJson(($this->cart)("/items/{$this->serum->id}"), ['quantity' => 3])->assertConflict();
});

it('refuses items that are not for sale or belong to another store', function (): void {
    asStore($this->store, fn () => $this->lipstick->product->update(['status' => ProductStatus::Draft]));
    add($this->lipstick)->assertUnprocessable()->assertJsonPath('error.code', 'VARIANT_UNAVAILABLE');

    $foreign = asStore(Tenant::factory()->create(), fn () => ProductVariant::factory()->create());
    add($foreign)->assertUnprocessable()->assertJsonPath('error.details.0.field', 'variant_id');
});

it('flags lines that can no longer be bought', function (): void {
    add($this->lipstick, 2);
    add($this->serum, 2);

    asStore($this->store, function (): void {
        $this->lipstick->update(['is_active' => false]);
        $this->serum->lots()->update(['quantity_reserved' => 1]);
    });

    $this->getJson(($this->cart)())
        ->assertJsonPath('data.items.0.problem', 'unavailable')
        ->assertJsonPath('data.items.1.problem', 'insufficient_stock')
        ->assertJsonPath('data.subtotal_cents', 23980)
        ->assertJsonPath('data.can_checkout', false);
});

it('updates, removes and clears lines', function (): void {
    add($this->lipstick, 2);
    add($this->serum);

    $this->patchJson(($this->cart)("/items/{$this->lipstick->id}"), ['quantity' => 5])->assertJsonPath('data.items.0.quantity', 5);
    $this->deleteJson(($this->cart)("/items/{$this->lipstick->id}"))->assertJsonCount(1, 'data.items');
    $this->deleteJson(($this->cart)())->assertJsonCount(0, 'data.items')->assertJsonPath('data.can_checkout', false);
});

it('keeps each customer\'s cart to themselves', function (): void {
    add($this->lipstick, 3);

    app('auth')->forgetGuards();
    $this->withToken(tokenFor($this->store, UserRole::Customer))
        ->getJson(($this->cart)())->assertJsonCount(0, 'data.items');
});

it('requires signing in', function (): void {
    $this->withoutToken()->getJson(($this->cart)())->assertUnauthorized();
});
