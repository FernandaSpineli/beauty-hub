<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\Tenant;

/**
 * What the app needs from the payment provider. Stripe in production; a fake
 * in tests, which never call the network.
 */
interface PaymentGateway
{
    /** Creates the store's connected (Express) account; returns its ID. */
    public function createConnectedAccount(Tenant $store, string $ownerEmail): string;

    /** A one-time link where the store owner completes onboarding. */
    public function onboardingUrl(string $accountId, string $returnUrl, string $refreshUrl): string;

    /** Whether the connected account can take payments yet. */
    public function chargesEnabled(string $accountId): bool;

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession;
}
