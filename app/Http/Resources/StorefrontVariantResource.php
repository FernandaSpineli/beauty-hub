<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shade or size as shoppers see it: whether it can be bought, not how many
 * units the store holds, and the expiry month of the unit that would ship.
 *
 * @mixin ProductVariant
 */
final class StorefrontVariantResource extends JsonResource
{
    public const int LOW_STOCK = 3;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $available = $this->stock->available ?? 0;

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'shade_hex' => $this->shade_hex,
            'price_cents' => $this->price_cents,
            'compare_at_price_cents' => $this->compare_at_price_cents,
            'in_stock' => $available > 0,
            'low_stock' => $available > 0 && $available <= self::LOW_STOCK,
            'ships_with_expiry' => $available > 0 ? $this->stock?->next_expiry?->format('Y-m') : null,
        ];
    }
}
