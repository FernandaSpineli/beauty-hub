<?php

declare(strict_types=1);

namespace App\Payments;

use App\Exceptions\ApiException;
use App\Models\Tenant;

/**
 * Used when no Stripe keys are configured: everything but payments works.
 */
final class UnavailableGateway implements PaymentGateway
{
    public function createConnectedAccount(Tenant $store, string $ownerEmail): string
    {
        throw self::unavailable();
    }

    public function onboardingUrl(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        throw self::unavailable();
    }

    public function chargesEnabled(string $accountId): bool
    {
        throw self::unavailable();
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession
    {
        throw self::unavailable();
    }

    private static function unavailable(): ApiException
    {
        return new ApiException(503, 'PAYMENTS_UNAVAILABLE', 'Payments are not configured on this server.');
    }
}
