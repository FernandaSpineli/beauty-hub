<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * pending_payment ──paid──▶ paid ──▶ shipped ──▶ delivered
 *        │
 *        ├──cancelled (by the customer or the store)
 *        └──expired   (not paid in time)
 */
enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::PendingPayment => [self::Paid, self::Cancelled, self::Expired],
            self::Paid => [self::Shipped],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled, self::Expired => [],
        }, true);
    }

    /** Whether the order still holds reserved (not yet sold) stock. */
    public function holdsReservation(): bool
    {
        return $this === self::PendingPayment;
    }
}
