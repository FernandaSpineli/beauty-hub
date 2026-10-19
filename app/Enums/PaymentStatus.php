<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentStatus: string
{
    case Open = 'open';

    /** Delayed methods (boleto): paid by the customer, not yet confirmed. */
    case Processing = 'processing';

    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';

    /** Money arrived for an order that can no longer take it (late, duplicate, wrong amount). */
    case NeedsRefund = 'needs_refund';
}
