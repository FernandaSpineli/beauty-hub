<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Exceptions\ApiException;

final class InsufficientStock
{
    /**
     * @param  list<array{variant_id: string, requested: int, available: int}>  $shortages
     */
    public static function for(array $shortages): ApiException
    {
        return new ApiException(409, 'INSUFFICIENT_STOCK', 'Not enough units in stock for some items.', $shortages);
    }
}
