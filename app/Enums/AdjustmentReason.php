<?php

declare(strict_types=1);

namespace App\Enums;

enum AdjustmentReason: string
{
    case Damaged = 'damaged';
    case Expired = 'expired';
    case CountCorrection = 'count_correction';
    case Returned = 'returned';
    case Other = 'other';
}
