<?php

declare(strict_types=1);

namespace App\Payments;

use Illuminate\Support\Carbon;

/**
 * One Stripe Checkout session for an order: the whole (discounted) total as
 * a single line, paid to the store's connected account minus the platform
 * fee.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public string $tenantId,
        public string $orderId,
        public string $description,
        public int $amountCents,
        public int $applicationFeeCents,
        public string $destinationAccountId,
        public string $customerEmail,
        public string $successUrl,
        public string $cancelUrl,
        public Carbon $expiresAt,
        public string $idempotencyKey,
    ) {}
}
