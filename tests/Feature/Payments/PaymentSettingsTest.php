<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Payments\PaymentGateway;
use Tests\Support\FakePaymentGateway;

beforeEach(function (): void {
    $this->gateway = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->gateway);
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
});

it('connects the store to Stripe once and returns the onboarding link', function (): void {
    $this->withToken(tokenFor($this->store, UserRole::Owner));

    $url = $this->postJson($this->storeUrl('bella', 'admin/payments/onboarding'))->assertOk()->json('data.url');
    $this->postJson($this->storeUrl('bella', 'admin/payments/onboarding'))->assertOk();

    expect($url)->toStartWith('https://connect.stripe.test/setup/acct_')
        ->and(urldecode($url))->toContain('bella.beautyhub.localhost:8000/admin/pagamentos')
        ->and($this->gateway->accountsCreated)->toHaveCount(1)
        ->and($this->store->fresh()?->stripe_account_id)->toStartWith('acct_');
});

it('reports whether the account can take payments', function (): void {
    $this->store->forceFill(['stripe_account_id' => 'acct_bella123'])->save();
    $this->withToken(tokenFor($this->store, UserRole::Owner));

    $this->getJson($this->storeUrl('bella', 'admin/payments'))
        ->assertOk()
        ->assertJsonPath('data.connected', true)
        ->assertJsonPath('data.charges_enabled', true);
    expect($this->store->fresh()?->stripe_charges_enabled)->toBeTrue();
});

it('is for owners only: staff decide nothing about where the money goes', function (): void {
    $this->withToken(tokenFor($this->store, UserRole::Staff));

    $this->getJson($this->storeUrl('bella', 'admin/payments'))->assertForbidden();
    $this->postJson($this->storeUrl('bella', 'admin/payments/onboarding'))->assertForbidden();
});
