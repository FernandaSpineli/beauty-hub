<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as a card in the storefront grid: lowest price and the shade
 * swatches available.
 *
 * @mixin Product
 */
final class ProductSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image_url' => $this->image_url,
            'brand' => ['name' => $this->brand->name, 'slug' => $this->brand->slug],
            'from_price_cents' => is_numeric($price = $this->getAttribute('from_price_cents')) ? (int) $price : null,
            'in_stock' => $this->variants->contains(fn (ProductVariant $v): bool => ($v->stock->available ?? 0) > 0),
            'is_vegan' => $this->is_vegan,
            'is_cruelty_free' => $this->is_cruelty_free,
            'shades' => $this->variants
                ->filter(fn (ProductVariant $v): bool => $v->shade_hex !== null)
                ->map(fn (ProductVariant $v): array => ['name' => $v->name, 'hex' => $v->shade_hex])
                ->values(),
        ];
    }
}
