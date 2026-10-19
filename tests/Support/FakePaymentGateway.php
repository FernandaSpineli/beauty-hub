<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use App\Payments\CheckoutRequest;
use App\Payments\CheckoutSession;
use App\Payments\PaymentGateway;

/**
 * Records what the app asks of the payment provider; never touches the
 * network.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<CheckoutRequest> */
    public array $checkouts = [];

    /** @var list<string> */
    public array $accountsCreated = [];

    public bool $chargesEnabled = true;

    public function createConnectedAccount(Tenant $store, string $ownerEmail): string
    {
        $this->accountsCreated[] = $ownerEmail;

        return 'acct_'.substr(md5($store->id), 0, 16);
    }

    public function onboardingUrl(string $accountId, string $returnUrl, string $refreshUrl): string
    {
        return "https://connect.stripe.test/setup/{$accountId}?return=".urlencode($returnUrl);
    }

    public function chargesEnabled(string $accountId): bool
    {
        return $this->chargesEnabled;
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession
    {
        $this->checkouts[] = $request;
        $id = 'cs_test_'.count($this->checkouts).substr(md5($request->orderId), 0, 8);

        return new CheckoutSession($id, "https://checkout.stripe.test/{$id}");
    }
}
