<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\Tenant;
use Stripe\StripeClient;

final readonly class StripeGateway implements PaymentGateway
{
    public function __construct(private StripeClient $stripe) {}

    public function createConnectedAccount(Tenant $store, string $ownerEmail): string
    {
        $account = $this->stripe->accounts->create([
            'type' => 'express',
            'country' => 'BR',
            'email' => $ownerEmail,
            'business_profile' => ['name' => $store->name],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => ['tenant_id' => $store->id],
        ], ['idempotency_key' => "connect-account-{$store->id}"]);

        return $account->id;
    }

    public function onboardingUrl(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return $this->stripe->accountLinks->create([
            'account' => $accountId,
            'type' => 'account_onboarding',
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
        ])->url;
    }

    public function chargesEnabled(string $accountId): bool
    {
        return (bool) $this->stripe->accounts->retrieve($accountId)->charges_enabled;
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession
    {
        $metadata = ['tenant_id' => $request->tenantId, 'order_id' => $request->orderId];

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => $request->orderId,
            'customer_email' => $request->customerEmail,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'brl',
                    'unit_amount' => $request->amountCents,
                    'product_data' => ['name' => $request->description],
                ],
            ]],
            // Destination charge: the store receives the payment, the
            // platform keeps its fee.
            'payment_intent_data' => [
                'application_fee_amount' => $request->applicationFeeCents,
                'transfer_data' => ['destination' => $request->destinationAccountId],
                'metadata' => $metadata,
            ],
            'metadata' => $metadata,
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'expires_at' => $request->expiresAt->getTimestamp(),
        ], ['idempotency_key' => $request->idempotencyKey]);

        return new CheckoutSession($session->id, (string) $session->url);
    }
}
