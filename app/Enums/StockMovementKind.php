<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementKind: string
{
    case Received = 'received';
    case Adjusted = 'adjusted';
    case Reserved = 'reserved';
    case Released = 'released';
    case Sold = 'sold';
}
