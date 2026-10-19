<?php

declare(strict_types=1);

namespace App\Inventory;

/**
 * Units reserved from one lot. For a kit, $variantId is the component that
 * holds the stock and $forVariantId the kit that was ordered.
 */
final readonly class Allocation
{
    public function __construct(
        public string $lotId,
        public string $variantId,
        public string $forVariantId,
        public int $quantity,
    ) {}
}
