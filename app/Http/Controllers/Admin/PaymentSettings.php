<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Payments\PaymentGateway;
use App\Payments\StartCheckout;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The store's Stripe Connect account. Owners only: it decides where the
 * money goes.
 */
final readonly class PaymentSettings
{
    public function __construct(
        private TenantContext $context,
        private PaymentGateway $gateway,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize('manage-payments');
        $store = $this->context->require();

        if ($store->stripe_account_id !== null) {
            $store->stripe_charges_enabled = $this->gateway->chargesEnabled($store->stripe_account_id);
            $store->save();
        }

        return new JsonResponse(['data' => [
            'connected' => $store->stripe_account_id !== null,
            'charges_enabled' => $store->stripe_charges_enabled,
            'platform_fee_bps' => config()->integer('services.stripe.platform_fee_bps'),
        ]]);
    }

    public function onboarding(Request $request, StartCheckout $payments): JsonResponse
    {
        Gate::authorize('manage-payments');
        $owner = $request->user();
        assert($owner instanceof User);

        return new JsonResponse(['data' => [
            'url' => $payments->onboardingUrl($this->context->require(), $owner->email),
        ]]);
    }
}
