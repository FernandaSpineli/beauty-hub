<?php

declare(strict_types=1);

namespace App\Cart;

use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Whether a coupon can apply to a subtotal right now. Usage limits are
 * checked again, under a lock, when the order is placed.
 */
final class CouponRules
{
    public static function problem(Coupon $coupon, int $subtotalCents, ?Carbon $now = null): ?ApiException
    {
        $now ??= now();

        return match (true) {
            ! $coupon->is_active => new ApiException(422, 'COUPON_INACTIVE', 'This coupon is not active.'),
            $coupon->starts_at?->isAfter($now) === true => new ApiException(422, 'COUPON_NOT_STARTED', 'This coupon is not valid yet.'),
            $coupon->ends_at !== null && ! $coupon->ends_at->isAfter($now) => new ApiException(422, 'COUPON_EXPIRED', 'This coupon has expired.'),
            $coupon->max_redemptions !== null && $coupon->times_redeemed >= $coupon->max_redemptions => new ApiException(422, 'COUPON_EXHAUSTED', 'This coupon has run out.'),
            $subtotalCents < $coupon->min_subtotal_cents => new ApiException(422, 'COUPON_MIN_SUBTOTAL', 'The cart is below this coupon\'s minimum.', [
                ['min_subtotal_cents' => $coupon->min_subtotal_cents, 'subtotal_cents' => $subtotalCents],
            ]),
            default => null,
        };
    }

    /**
     * Whether this customer has already used the coupon as often as allowed.
     * Redemptions of cancelled or expired orders are removed, so they do not
     * count.
     */
    public static function usedUp(Coupon $coupon, User $customer): ?ApiException
    {
        $used = CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->where('user_id', $customer->id)
            ->count();

        return $used >= $coupon->max_per_customer
            ? new ApiException(422, 'COUPON_ALREADY_USED', 'You have already used this coupon.')
            : null;
    }
}
