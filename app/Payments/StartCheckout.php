<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Tenancy\StoreUrl;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Opens (or reuses) a Stripe Checkout session for an order awaiting payment.
 */
final readonly class StartCheckout
{
    /** Stripe refuses sessions expiring sooner than 30 minutes. */
    public const int SESSION_MINUTES = 31;

    public function __construct(
        private PaymentGateway $gateway,
        private TenantContext $context,
    ) {}

    public function handle(Order $order): Payment
    {
        $store = $this->context->require();

        return DB::transaction(function () use ($order, $store): Payment {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::PendingPayment) {
                throw new ApiException(409, 'ORDER_NOT_PAYABLE', "The order is {$order->status->value}.");
            }
            if ($store->stripe_account_id === null || ! $store->stripe_charges_enabled) {
                throw new ApiException(409, 'STORE_PAYMENTS_NOT_READY', 'This store is not accepting payments yet.');
            }

            $open = Payment::query()
                ->where('order_id', $order->id)
                ->where('status', PaymentStatus::Open)
                ->where('expires_at', '>', now()->addMinutes(5))
                ->first();
            if ($open !== null) {
                return $open;
            }

            $attempt = Payment::query()->where('order_id', $order->id)->count() + 1;
            $expiresAt = now()->addMinutes(self::SESSION_MINUTES)->startOfSecond();
            $fee = intdiv($order->total_cents * config()->integer('services.stripe.platform_fee_bps'), 10_000);

            $session = $this->gateway->createCheckoutSession(new CheckoutRequest(
                tenantId: $store->id,
                orderId: $order->id,
                description: "Pedido {$order->reference()} — {$store->name}",
                amountCents: $order->total_cents,
                applicationFeeCents: $fee,
                destinationAccountId: $store->stripe_account_id,
                customerEmail: $order->customer_email,
                successUrl: StoreUrl::to($store, "pedidos/{$order->id}", ['pagamento' => 'concluido']),
                cancelUrl: StoreUrl::to($store, "pedidos/{$order->id}", ['pagamento' => 'cancelado']),
                expiresAt: $expiresAt,
                idempotencyKey: "checkout-{$order->id}-{$attempt}",
            ));

            // Hold the stock for as long as the session can still be paid.
            if ($order->expires_at->lessThan($expiresAt->copy()->addMinutes(5))) {
                $order->update(['expires_at' => $expiresAt->copy()->addMinutes(5)]);
            }

            return Payment::query()->create([
                'order_id' => $order->id,
                'checkout_session_id' => $session->id,
                'checkout_url' => $session->url,
                'amount_cents' => $order->total_cents,
                'application_fee_cents' => $fee,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    /**
     * Connects the store to Stripe if needed and returns where its owner
     * continues onboarding.
     */
    public function onboardingUrl(Tenant $store, string $ownerEmail): string
    {
        if ($store->stripe_account_id === null) {
            $store->stripe_account_id = $this->gateway->createConnectedAccount($store, $ownerEmail);
            $store->save();
        }

        return $this->gateway->onboardingUrl(
            $store->stripe_account_id,
            StoreUrl::to($store, 'admin/pagamentos', ['onboarding' => 'retorno']),
            StoreUrl::to($store, 'admin/pagamentos', ['onboarding' => 'renovar']),
        );
    }
}
