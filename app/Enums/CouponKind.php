<?php

declare(strict_types=1);

namespace App\Enums;

enum CouponKind: string
{
    /** value is a percentage of the subtotal (1-100). */
    case Percent = 'percent';

    /** value is an amount in centavos. */
    case Fixed = 'fixed';
}
