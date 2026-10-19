<?php

declare(strict_types=1);

use App\Models\Coupon;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    $this->withToken(tokenFor($this->store));
    $this->url = fn (string $path = ''): string => $this->storeUrl('bella', 'admin/coupons'.$path);
});

it('creates coupons with normalised codes', function (): void {
    $this->postJson(($this->url)(), [
        'code' => ' verao26 ', 'kind' => 'percent', 'value' => 20,
        'min_subtotal_cents' => 10000, 'max_redemptions' => 100,
        'starts_at' => '2026-12-01T00:00:00-03:00', 'ends_at' => '2027-03-01T00:00:00-03:00',
    ])->assertCreated()
        ->assertJsonPath('data.code', 'VERAO26')
        ->assertJsonPath('data.max_per_customer', 1)
        ->assertJsonPath('data.times_redeemed', 0);

    $this->postJson(($this->url)(), ['code' => 'VERAO26', 'kind' => 'fixed', 'value' => 500])
        ->assertUnprocessable()->assertJsonPath('error.details.0.field', 'code');
});

it('validates coupons', function (array $payload, string $field): void {
    $this->postJson(($this->url)(), [...['code' => 'OK123', 'kind' => 'percent', 'value' => 10], ...$payload])
        ->assertUnprocessable()->assertJsonFragment(['field' => $field]);
})->with([
    'over 100%' => [['value' => 101], 'value'],
    'bad code' => [['code' => 'ab'], 'code'],
    'ends before it starts' => [['starts_at' => '2027-01-02', 'ends_at' => '2027-01-01'], 'ends_at'],
]);

it('deactivates used coupons instead of deleting them', function (): void {
    [$used, $unused] = asStore($this->store, fn (): array => [
        Coupon::factory()->create(['times_redeemed' => 3]),
        Coupon::factory()->create(),
    ]);

    $this->deleteJson(($this->url)("/{$used->id}"))->assertConflict()->assertJsonPath('error.code', 'COUPON_IN_USE');
    $this->patchJson(($this->url)("/{$used->id}"), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->deleteJson(($this->url)("/{$unused->id}"))->assertNoContent();
});
