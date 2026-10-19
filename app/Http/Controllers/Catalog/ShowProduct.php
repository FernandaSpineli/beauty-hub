<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Resources\StorefrontProductResource;
use App\Models\Product;

final class ShowProduct
{
    public function __invoke(string $slug): StorefrontProductResource
    {
        $product = Product::query()
            ->visible()
            ->where('slug', $slug)
            ->with(['brand', 'category', 'variants' => fn ($q) => $q->where('is_active', true)->with('stock')])
            ->firstOrFail();

        return new StorefrontProductResource($product);
    }
}
