<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\StripeEvent;
use App\Models\Tenant;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakePaymentGateway;

beforeEach(function (): void {
    config(['services.stripe.webhook_secret' => 'whsec_test_secret']);
    app()->instance(PaymentGateway::class, new FakePaymentGateway);

    $this->store = Tenant::factory()->create(['slug' => 'bella', 'stripe_account_id' => 'acct_bella123', 'stripe_charges_enabled' => true]);
    [$variant, $this->lot] = asStore($this->store, function (): array {
        $v = ProductVariant::factory()->create(['price_cents' => 5000]);

        return [$v, StockLot::factory()->create(['variant_id' => $v->id, 'quantity_on_hand' => 10])];
    });
    $this->withToken(tokenFor($this->store, UserRole::Customer));
    $this->postJson($this->storeUrl('bella', 'cart/items'), ['variant_id' => $variant->id, 'quantity' => 2]);
    $this->orderId = $this->postJson($this->storeUrl('bella', 'orders'), ['shipping_address' => address()])->json('data.id');
    $this->postJson($this->storeUrl('bella', "orders/{$this->orderId}/checkout"))->assertOk();
    $this->session = asStore($this->store, fn () => Payment::query()->firstOrFail()->checkout_session_id);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function checkoutSession(array $overrides = []): array
{
    return [
        'id' => test()->session,
        'object' => 'checkout.session',
        'amount_total' => 10000,
        'payment_status' => 'paid',
        'payment_intent' => 'pi_test_123',
        'metadata' => ['tenant_id' => test()->store->id, 'order_id' => test()->orderId],
        ...$overrides,
    ];
}

function orderStatus(): string
{
    return asStore(test()->store, fn () => Order::query()->findOrFail(test()->orderId)->status->value);
}

function paymentStatus(): string
{
    return asStore(test()->store, fn () => Payment::query()->firstOrFail()->status->value);
}

it('marks the order paid and sells the reserved stock', function (): void {
    stripeEvent('checkout.session.completed', checkoutSession())->assertOk()->assertJson(['received' => true]);

    expect(orderStatus())->toBe('paid')
        ->and(paymentStatus())->toBe('succeeded')
        ->and(asStore($this->store, fn () => [$this->lot->fresh()->quantity_on_hand, $this->lot->fresh()->quantity_reserved]))->toBe([8, 0]);
});

it('applies a redelivered event only once', function (): void {
    stripeEvent('checkout.session.completed', checkoutSession(), 'evt_same')->assertOk();
    stripeEvent('checkout.session.completed', checkoutSession(), 'evt_same')->assertOk();

    expect(StripeEvent::query()->count())->toBe(1)
        ->and(asStore($this->store, fn () => StockMovement::query()->where('kind', 'sold')->count()))->toBe(1);
});

it('rejects events without a valid signature', function (): void {
    stripeEvent('checkout.session.completed', checkoutSession(), secret: 'whsec_forged')
        ->assertStatus(400)->assertJsonPath('error.code', 'INVALID_SIGNATURE');

    expect(orderStatus())->toBe('pending_payment')->and(StripeEvent::query()->count())->toBe(0);
});

it('waits for delayed methods like boleto, then settles', function (): void {
    stripeEvent('checkout.session.completed', checkoutSession(['payment_status' => 'unpaid']));
    expect(orderStatus())->toBe('pending_payment')->and(paymentStatus())->toBe('processing');

    stripeEvent('checkout.session.async_payment_succeeded', checkoutSession());
    expect(orderStatus())->toBe('paid')->and(paymentStatus())->toBe('succeeded');
});

it('records failed and expired sessions without touching the order', function (string $type, string $status): void {
    stripeEvent($type, checkoutSession(['payment_status' => 'unpaid']));

    expect(paymentStatus())->toBe($status)->and(orderStatus())->toBe('pending_payment');
})->with([
    ['checkout.session.async_payment_failed', 'failed'],
    ['checkout.session.expired', 'expired'],
]);

it('flags a payment of the wrong amount instead of accepting it', function (): void {
    stripeEvent('checkout.session.completed', checkoutSession(['amount_total' => 100]));

    expect(orderStatus())->toBe('pending_payment')->and(paymentStatus())->toBe('needs_refund');
});

it('flags a payment that arrives after the order expired', function (): void {
    $this->travel(2)->hours();
    Artisan::call('orders:expire');

    stripeEvent('checkout.session.completed', checkoutSession())->assertOk();

    expect(orderStatus())->toBe('expired')
        ->and(paymentStatus())->toBe('needs_refund')
        ->and(asStore($this->store, fn () => $this->lot->fresh()->quantity_on_hand))->toBe(10);
});

it('keeps the store\'s payment readiness in sync with Stripe', function (): void {
    stripeEvent('account.updated', ['id' => 'acct_bella123', 'object' => 'account', 'charges_enabled' => false]);

    expect($this->store->fresh()?->stripe_charges_enabled)->toBeFalse();
});

it('answers 503 when webhooks are not configured', function (): void {
    config(['services.stripe.webhook_secret' => null]);

    stripeEvent('checkout.session.completed', checkoutSession(), secret: 'x')->assertServiceUnavailable();
});
