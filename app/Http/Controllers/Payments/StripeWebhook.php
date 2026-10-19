<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Exceptions\ApiException;
use App\Jobs\ProcessStripeEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * POST /api/v1/stripe/webhook (any host). Verifies the
 * signature, stores the event once (redeliveries are acknowledged and
 * skipped) and leaves the work to a queued job, so Stripe gets a fast 200.
 */
final class StripeWebhook
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            throw new ApiException(503, 'PAYMENTS_UNAVAILABLE', 'Payments are not configured on this server.');
        }

        $payload = $request->getContent();
        try {
            $event = Webhook::constructEvent($payload, (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            Log::warning('stripe.invalid_signature', ['ip' => $request->ip()]);

            throw new ApiException(400, 'INVALID_SIGNATURE', 'The webhook signature is invalid.');
        }

        $stored = DB::table('stripe_events')->insertOrIgnore([
            'id' => $event->id,
            'type' => $event->type,
            'payload' => $payload,
        ]);

        if ($stored === 1) {
            ProcessStripeEvent::dispatch($event->id);
        } else {
            Log::info('stripe.duplicate_event', ['event_id' => $event->id]);
        }

        return new JsonResponse(['received' => true]);
    }
}
