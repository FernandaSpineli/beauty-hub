<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Payments\PaymentGateway;
use App\Payments\UnavailableGateway;
use Tests\Support\FakePaymentGateway;

beforeEach(function (): void {
    config(['services.stripe.platform_fee_bps' => 500]);
    $this->gateway = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->gateway);

    $this->store = Tenant::factory()->create([
        'slug' => 'bella', 'name' => 'Bella', 'stripe_account_id' => 'acct_bella123', 'stripe_charges_enabled' => true,
    ]);
    $variant = asStore($this->store, function (): ProductVariant {
        $v = ProductVariant::factory()->create(['price_cents' => 4990]);
        StockLot::factory()->create(['variant_id' => $v->id]);

        return $v;
    });
    $this->withToken(tokenFor($this->store, UserRole::Customer));
    $this->postJson($this->storeUrl('bella', 'cart/items'), ['variant_id' => $variant->id, 'quantity' => 2]);
    $this->order = $this->postJson($this->storeUrl('bella', 'orders'), ['shipping_address' => address()])->json('data');
    $this->checkoutUrl = $this->storeUrl('bella', "orders/{$this->order['id']}/checkout");
});

it('opens a Checkout session paying the store, minus the platform fee', function (): void {
    $this->travel(20)->minutes();

    $response = $this->postJson($this->checkoutUrl)->assertOk();

    $request = $this->gateway->checkouts[0];
    expect($request->amountCents)->toBe(9980)
        ->and($request->applicationFeeCents)->toBe(499)
        ->and($request->destinationAccountId)->toBe('acct_bella123')
        ->and($request->description)->toBe("Pedido {$this->order['reference']} — Bella")
        ->and($request->successUrl)->toStartWith('http://bella.beautyhub.localhost:8000/pedidos/')
        ->and(now()->diffInMinutes($request->expiresAt))->toBeGreaterThanOrEqual(30.0)
        ->and($response->json('data.checkout_url'))->toStartWith('https://checkout.stripe.test/');

    // The order now holds its stock until after the session closes.
    $order = asStore($this->store, fn () => Order::query()->findOrFail($this->order['id']));
    expect($order->expires_at->greaterThan($request->expiresAt))->toBeTrue();
});

it('reuses the open session instead of opening another', function (): void {
    $first = $this->postJson($this->checkoutUrl)->json('data.checkout_url');

    expect($this->postJson($this->checkoutUrl)->json('data.checkout_url'))->toBe($first)
        ->and($this->gateway->checkouts)->toHaveCount(1);
});

it('refuses when the store cannot take payments yet, or payments are off', function (): void {
    $this->store->forceFill(['stripe_charges_enabled' => false])->save();
    $this->postJson($this->checkoutUrl)->assertConflict()->assertJsonPath('error.code', 'STORE_PAYMENTS_NOT_READY');

    $this->store->forceFill(['stripe_charges_enabled' => true])->save();
    app()->instance(PaymentGateway::class, new UnavailableGateway);
    $this->postJson($this->checkoutUrl)->assertServiceUnavailable()->assertJsonPath('error.code', 'PAYMENTS_UNAVAILABLE');
});

it('only opens checkout for the customer\'s own unpaid orders', function (): void {
    $this->postJson($this->storeUrl('bella', "orders/{$this->order['id']}/cancel"));
    $this->postJson($this->checkoutUrl)->assertConflict()->assertJsonPath('error.code', 'ORDER_NOT_PAYABLE');

    app('auth')->forgetGuards();
    $this->withToken(tokenFor($this->store, UserRole::Customer))->postJson($this->checkoutUrl)->assertNotFound();
});
