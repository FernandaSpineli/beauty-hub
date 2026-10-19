<?php

declare(strict_types=1);

use App\Enums\CouponKind;
use App\Enums\UserRole;
use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    $this->variant = asStore($this->store, function (): ProductVariant {
        $variant = ProductVariant::factory()->create(['price_cents' => 5000]);
        StockLot::factory()->create(['variant_id' => $variant->id, 'quantity_on_hand' => 20]);

        return $variant;
    });
    $this->withToken(tokenFor($this->store, UserRole::Customer));
    $this->postJson($this->storeUrl('bella', 'cart/items'), ['variant_id' => $this->variant->id, 'quantity' => 2]);
});

function coupon(array $attributes = []): Coupon
{
    return asStore(test()->store, fn () => Coupon::factory()->create($attributes));
}

function applyCoupon(string $code): TestResponse
{
    return test()->putJson(test()->storeUrl('bella', 'cart/coupon'), ['code' => $code]);
}

it('applies percentage and fixed discounts', function (array $attributes, int $discount): void {
    coupon(['code' => 'BELEZA', ...$attributes]);

    applyCoupon('beleza')->assertOk()
        ->assertJsonPath('data.subtotal_cents', 10000)
        ->assertJsonPath('data.coupon.code', 'BELEZA')
        ->assertJsonPath('data.discount_cents', $discount)
        ->assertJsonPath('data.total_cents', 10000 - $discount);
})->with([
    '15%' => [['kind' => CouponKind::Percent, 'value' => 15], 1500],
    'R$ 20' => [['kind' => CouponKind::Fixed, 'value' => 2000], 2000],
    'never more than the cart' => [['kind' => CouponKind::Fixed, 'value' => 50000], 10000],
]);

it('refuses coupons that cannot apply', function (array $attributes, string $code): void {
    coupon(['code' => 'BELEZA', ...$attributes]);

    applyCoupon('BELEZA')->assertUnprocessable()->assertJsonPath('error.code', $code);
})->with([
    'inactive' => [['is_active' => false], 'COUPON_INACTIVE'],
    'not started' => [['starts_at' => now()->addDay()], 'COUPON_NOT_STARTED'],
    'expired' => [['ends_at' => now()->subMinute()], 'COUPON_EXPIRED'],
    'used up' => [['max_redemptions' => 5, 'times_redeemed' => 5], 'COUPON_EXHAUSTED'],
    'cart below minimum' => [['min_subtotal_cents' => 15000], 'COUPON_MIN_SUBTOTAL'],
]);

it('does not know other stores\' coupons', function (): void {
    asStore(Tenant::factory()->create(), fn () => Coupon::factory()->create(['code' => 'OUTRA']));

    applyCoupon('OUTRA')->assertUnprocessable()->assertJsonPath('error.code', 'COUPON_NOT_FOUND');
});

it('stops discounting when the cart falls below the minimum, and says why', function (): void {
    coupon(['code' => 'BELEZA', 'min_subtotal_cents' => 8000]);
    applyCoupon('BELEZA')->assertOk();

    $this->patchJson($this->storeUrl('bella', "cart/items/{$this->variant->id}"), ['quantity' => 1])
        ->assertJsonPath('data.discount_cents', 0)
        ->assertJsonPath('data.coupon.problem.code', 'COUPON_MIN_SUBTOTAL');

    $this->deleteJson($this->storeUrl('bella', 'cart/coupon'))->assertJsonPath('data.coupon', null);
});
