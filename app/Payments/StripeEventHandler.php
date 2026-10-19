<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Models\Tenant;
use App\Orders\OrderTransitions;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a stored Stripe event exactly once. The event row is locked and
 * marked processed in the same transaction as its effects, so a redelivery
 * or a retried job finds it done.
 */
final readonly class StripeEventHandler
{
    public function __construct(
        private TenantContext $context,
        private OrderTransitions $transitions,
    ) {}

    public function handle(string $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            $event = StripeEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->processed_at !== null) {
                return;
            }

            /** @var array<string, mixed> $object */
            $object = data_get($event->payload, 'data.object', []);

            $outcome = match ($event->type) {
                'checkout.session.completed' => data_get($object, 'payment_status') === 'paid'
                    ? $this->settle($object)
                    : $this->setStatus($object, PaymentStatus::Processing),
                'checkout.session.async_payment_succeeded' => $this->settle($object),
                'checkout.session.async_payment_failed' => $this->setStatus($object, PaymentStatus::Failed),
                'checkout.session.expired' => $this->setStatus($object, PaymentStatus::Expired),
                'account.updated' => $this->updateAccount($object),
                default => 'ignored',
            };

            $event->update(['processed_at' => now(), 'outcome' => $outcome]);
            Log::info('stripe.event_processed', ['event_id' => $event->id, 'type' => $event->type, 'outcome' => $outcome]);
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function settle(array $session): string
    {
        return $this->forSession($session, function (Payment $payment) use ($session): string {
            $payment->payment_intent_id = $this->string($session, 'payment_intent');
            $paid = is_numeric($total = data_get($session, 'amount_total')) ? (int) $total : 0;

            if ($paid !== $payment->amount_cents) {
                return $this->needsRefund($payment, "Paid {$paid} centavos, expected {$payment->amount_cents}.", 'stripe.amount_mismatch');
            }

            try {
                $this->transitions->markPaid($payment->order, "Stripe {$payment->checkout_session_id}");
            } catch (ApiException $e) {
                if ($e->errorCode !== 'ORDER_STATUS_CONFLICT') {
                    throw $e;
                }

                return $this->needsRefund($payment, 'Payment arrived for an order that is '.$payment->order->fresh()?->status->value.'.', 'stripe.late_or_duplicate_payment');
            }

            $payment->update(['status' => PaymentStatus::Succeeded]);

            return 'paid';
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function setStatus(array $session, PaymentStatus $status): string
    {
        return $this->forSession($session, function (Payment $payment) use ($status): string {
            if (in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::NeedsRefund], true)) {
                return 'already_settled';
            }

            $payment->update(['status' => $status]);

            return $status->value;
        });
    }

    /**
     * Runs as the store named in the session's metadata, with the payment row
     * locked.
     *
     * @param  array<string, mixed>  $session
     * @param  Closure(Payment): string  $apply
     */
    private function forSession(array $session, Closure $apply): string
    {
        $store = Tenant::query()->find($this->string($session, 'metadata.tenant_id'));
        if ($store === null) {
            return 'unknown_store';
        }

        return $this->context->run($store, function () use ($session, $apply): string {
            $payment = Payment::query()
                ->with('order')
                ->where('checkout_session_id', $this->string($session, 'id'))
                ->lockForUpdate()
                ->first();

            return $payment === null ? 'unknown_session' : $apply($payment);
        });
    }

    private function needsRefund(Payment $payment, string $note, string $logEvent): string
    {
        $payment->update(['status' => PaymentStatus::NeedsRefund, 'note' => $note]);
        Log::warning($logEvent, ['payment_id' => $payment->id, 'order_id' => $payment->order_id, 'note' => $note]);

        return 'needs_refund';
    }

    /**
     * @param  array<string, mixed>  $account
     */
    private function updateAccount(array $account): string
    {
        $updated = Tenant::query()
            ->where('stripe_account_id', $this->string($account, 'id'))
            ->update(['stripe_charges_enabled' => (bool) data_get($account, 'charges_enabled', false)]);

        return $updated === 1 ? 'account_updated' : 'unknown_account';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(array $data, string $key): string
    {
        $value = data_get($data, $key);

        return is_string($value) ? $value : '';
    }
}
