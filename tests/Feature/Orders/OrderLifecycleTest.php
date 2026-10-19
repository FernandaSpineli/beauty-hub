<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Orders\OrderTransitions;
use Illuminate\Support\Facades\Artisan;
use Tests\FeatureTestCase;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    [$this->variant, $this->lot, $this->coupon] = asStore($this->store, function (): array {
        $variant = ProductVariant::factory()->create(['price_cents' => 5000]);

        return [
            $variant,
            StockLot::factory()->create(['variant_id' => $variant->id, 'quantity_on_hand' => 10, 'lot_code' => 'L2026A']),
            Coupon::factory()->create(['code' => 'DEZ', 'max_redemptions' => 10]),
        ];
    });
    $this->customer = tokenFor($this->store, UserRole::Customer);
    $this->owner = tokenFor($this->store, UserRole::Owner);
});

/**
 * Places an order for the given quantity as the customer, with the coupon.
 */
function placeOrder(int $quantity = 2): array
{
    app('auth')->forgetGuards();
    test()->withToken(test()->customer);
    test()->postJson(test()->storeUrl('bella', 'cart/items'), ['variant_id' => test()->variant->id, 'quantity' => $quantity]);
    test()->putJson(test()->storeUrl('bella', 'cart/coupon'), ['code' => 'DEZ']);

    return test()->postJson(test()->storeUrl('bella', 'orders'), ['shipping_address' => address()])->assertCreated()->json('data');
}

function stock(): array
{
    return asStore(test()->store, fn () => [test()->lot->fresh()->quantity_on_hand, test()->lot->fresh()->quantity_reserved]);
}

function pay(string $orderId): void
{
    asStore(test()->store, fn () => app(OrderTransitions::class)->markPaid(Order::query()->findOrFail($orderId), 'test'));
}

function asOwner(): FeatureTestCase
{
    app('auth')->forgetGuards();

    return test()->withToken(test()->owner);
}

it('gives stock and coupon use back when the customer cancels', function (): void {
    $order = placeOrder();
    expect(stock())->toBe([10, 2]);

    $this->postJson($this->storeUrl('bella', "orders/{$order['id']}/cancel"))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.history.1.from', 'pending_payment');

    expect(stock())->toBe([10, 0])
        ->and(asStore($this->store, fn () => [$this->coupon->fresh()->times_redeemed, CouponRedemption::query()->count()]))->toBe([0, 0]);

    $this->postJson($this->storeUrl('bella', "orders/{$order['id']}/cancel"))
        ->assertConflict()->assertJsonPath('error.code', 'ORDER_STATUS_CONFLICT');
});

it('sells the reserved units on payment, then ships and delivers', function (): void {
    $order = placeOrder();
    pay($order['id']);
    expect(stock())->toBe([8, 0]);

    $this->postJson($this->storeUrl('bella', "orders/{$order['id']}/cancel"))->assertConflict();

    $url = $this->storeUrl('bella', "admin/orders/{$order['id']}/status");
    asOwner()->patchJson($url, ['status' => 'delivered'])->assertConflict();
    asOwner()->patchJson($url, ['status' => 'shipped', 'note' => 'Correios BR123'])->assertOk()->assertJsonPath('data.status', 'shipped');
    asOwner()->patchJson($url, ['status' => 'delivered'])->assertOk()
        ->assertJsonPath('data.history.*.to', ['pending_payment', 'paid', 'shipped', 'delivered'])
        ->assertJsonPath('data.history.2.note', 'Correios BR123');
});

it('lets staff cancel an unpaid order but not mark one paid', function (): void {
    $order = placeOrder();
    $url = $this->storeUrl('bella', "admin/orders/{$order['id']}/status");

    asOwner()->patchJson($url, ['status' => 'paid'])->assertUnprocessable();
    asOwner()->patchJson($url, ['status' => 'cancelled'])->assertOk();
    expect(stock())->toBe([10, 0]);
});

it('shows customers only their own orders', function (): void {
    $order = placeOrder();

    app('auth')->forgetGuards();
    $this->withToken(tokenFor($this->store, UserRole::Customer));
    $this->getJson($this->storeUrl('bella', "orders/{$order['id']}"))->assertNotFound();
    $this->postJson($this->storeUrl('bella', "orders/{$order['id']}/cancel"))->assertNotFound();
    $this->getJson($this->storeUrl('bella', 'orders'))->assertJsonCount(0, 'data');

    asOwner()->getJson($this->storeUrl('bella', 'admin/orders?status=pending_payment'))->assertJsonCount(1, 'data');
});

it('expires unpaid orders past their window, in every store', function (): void {
    $due = placeOrder(3);
    $this->travel(30)->minutes();
    $notYet = placeOrder(1);
    $this->travel(20)->minutes();

    Artisan::call('orders:expire');

    $statuses = asStore($this->store, fn () => Order::query()->pluck('status', 'id')->map->value->all());
    expect($statuses)->toBe([$due['id'] => 'expired', $notYet['id'] => 'pending_payment'])
        ->and(stock())->toBe([10, 1]);
});

it('finds every paid order that received a recalled lot', function (): void {
    $paid = placeOrder(2);
    pay($paid['id']);
    placeOrder(1); // still unpaid: nothing shipped to this customer yet

    asOwner()->getJson($this->storeUrl('bella', "admin/lots/{$this->lot->id}/orders"))
        ->assertOk()
        ->assertJsonPath('data.lot.lot_code', 'L2026A')
        ->assertJsonCount(1, 'data.orders')
        ->assertJsonPath('data.orders.0.reference', $paid['reference'])
        ->assertJsonPath('data.orders.0.units_from_lot', 2);
});
