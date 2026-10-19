<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    [$this->lipstick, $this->soonLot, $this->laterLot] = asStore($this->store, function (): array {
        $lipstick = ProductVariant::factory()->create(['price_cents' => 2990, 'name' => 'Nude', 'sku' => 'BATOM-NUDE']);

        return [
            $lipstick,
            StockLot::factory()->create(['variant_id' => $lipstick->id, 'quantity_on_hand' => 2, 'expires_on' => today()->addMonths(3)]),
            StockLot::factory()->create(['variant_id' => $lipstick->id, 'quantity_on_hand' => 10, 'expires_on' => today()->addMonths(9)]),
        ];
    });
    $this->withToken(tokenFor($this->store, UserRole::Customer));
});

function fillCart(ProductVariant $variant, int $quantity): void
{
    test()->postJson(test()->storeUrl('bella', 'cart/items'), ['variant_id' => $variant->id, 'quantity' => $quantity])->assertOk();
}

function checkout(array $address = [], ?string $key = null): TestResponse
{
    return test()->withHeaders($key ? ['Idempotency-Key' => $key] : [])
        ->postJson(test()->storeUrl('bella', 'orders'), ['shipping_address' => address($address)]);
}

it('turns the cart into an order holding first-expiring stock', function (): void {
    fillCart($this->lipstick, 3);

    $order = checkout()->assertCreated()
        ->assertJsonPath('data.reference', 'BH-000001')
        ->assertJsonPath('data.status', 'pending_payment')
        ->assertJsonPath('data.total_cents', 8970)
        ->assertJsonPath('data.items.0.sku', 'BATOM-NUDE')
        ->assertJsonPath('data.items.0.line_total_cents', 8970)
        ->assertJsonPath('data.history.0.to', 'pending_payment')
        ->json('data');

    expect(asStore($this->store, fn () => [$this->soonLot->fresh()->quantity_reserved, $this->laterLot->fresh()->quantity_reserved]))
        ->toBe([2, 1])
        ->and(asStore($this->store, fn () => OrderAllocation::query()->count()))->toBe(2)
        ->and($order['expires_at'])->toBe(now()->addMinutes(45)->startOfSecond()->toIso8601String());

    $this->getJson($this->storeUrl('bella', 'cart'))->assertJsonCount(0, 'data.items');
});

it('numbers orders per store', function (): void {
    fillCart($this->lipstick, 1);
    checkout()->assertJsonPath('data.reference', 'BH-000001');
    fillCart($this->lipstick, 1);
    checkout()->assertJsonPath('data.reference', 'BH-000002');

    $glow = Tenant::factory()->create(['slug' => 'glow']);
    $variant = asStore($glow, function (): ProductVariant {
        $v = ProductVariant::factory()->create();
        StockLot::factory()->create(['variant_id' => $v->id]);

        return $v;
    });
    app('auth')->forgetGuards();
    $this->withToken(tokenFor($glow, UserRole::Customer))
        ->postJson($this->storeUrl('glow', 'cart/items'), ['variant_id' => $variant->id]);
    $this->postJson($this->storeUrl('glow', 'orders'), ['shipping_address' => address()])
        ->assertJsonPath('data.reference', 'BH-000001');
});

it('returns the same order when a request is retried with its Idempotency-Key', function (): void {
    fillCart($this->lipstick, 2);

    $first = checkout(key: 'checkout-abc-123')->assertCreated()->json('data.id');
    checkout(key: 'checkout-abc-123')->assertOk()->assertJsonPath('data.id', $first);

    expect(asStore($this->store, fn () => Order::query()->count()))->toBe(1)
        ->and(asStore($this->store, fn () => StockLot::query()->sum('quantity_reserved')))->toBe(2);

    checkout(['number' => '99'], 'checkout-abc-123')
        ->assertUnprocessable()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    checkout(key: 'short')->assertStatus(400)->assertJsonPath('error.code', 'INVALID_IDEMPOTENCY_KEY');
});

it('refuses an empty cart or one with problems', function (): void {
    checkout()->assertUnprocessable()->assertJsonPath('error.code', 'CART_EMPTY');

    fillCart($this->lipstick, 3);
    asStore($this->store, fn () => $this->lipstick->update(['is_active' => false]));

    checkout()->assertConflict()
        ->assertJsonPath('error.code', 'CART_NOT_READY')
        ->assertJsonPath('error.details.0.problem', 'unavailable');
});

it('changes nothing when stock ran out since the cart was filled', function (): void {
    fillCart($this->lipstick, 5);
    // Someone else's order took most units meanwhile.
    asStore($this->store, fn () => $this->laterLot->update(['quantity_reserved' => 9]));

    checkout()->assertConflict()->assertJsonPath('error.code', 'CART_NOT_READY');

    expect(asStore($this->store, fn () => Order::query()->count()))->toBe(0);
    $this->getJson($this->storeUrl('bella', 'cart'))->assertJsonCount(1, 'data.items');
});

it('counts coupon use and enforces the per-customer limit', function (): void {
    $coupon = asStore($this->store, fn () => Coupon::factory()->create(['code' => 'BEMVINDA', 'value' => 10, 'max_per_customer' => 1]));
    fillCart($this->lipstick, 2);
    $this->putJson($this->storeUrl('bella', 'cart/coupon'), ['code' => 'BEMVINDA'])->assertOk();

    checkout()->assertCreated()
        ->assertJsonPath('data.coupon_code', 'BEMVINDA')
        ->assertJsonPath('data.discount_cents', 598)
        ->assertJsonPath('data.total_cents', 5382);
    expect(asStore($this->store, fn () => $coupon->fresh()->times_redeemed))->toBe(1);

    fillCart($this->lipstick, 1);
    $this->putJson($this->storeUrl('bella', 'cart/coupon'), ['code' => 'BEMVINDA'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'COUPON_ALREADY_USED');
});

it('keeps what was bought even if the catalog changes later', function (): void {
    fillCart($this->lipstick, 1);
    $id = checkout()->json('data.id');

    asStore($this->store, fn () => $this->lipstick->update(['price_cents' => 9990, 'name' => 'Renomeado']));

    $this->getJson($this->storeUrl('bella', "orders/{$id}"))
        ->assertJsonPath('data.items.0.unit_price_cents', 2990)
        ->assertJsonPath('data.items.0.variant_name', 'Nude');
});

it('validates and normalises the shipping address', function (): void {
    fillCart($this->lipstick, 1);

    checkout(['state' => 'XX'])->assertUnprocessable()->assertJsonPath('error.details.0.field', 'shipping_address.state');
    checkout(['postal_code' => '123'])->assertUnprocessable();
    $this->postJson($this->storeUrl('bella', 'orders'), ['shipping_address' => [...address(), 'evil' => 'x']])->assertUnprocessable();

    checkout(['postal_code' => '01310100'])->assertCreated()->assertJsonPath('data.shipping_address.postal_code', '01310-100');
});
