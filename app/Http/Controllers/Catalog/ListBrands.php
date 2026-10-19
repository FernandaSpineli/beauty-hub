<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Brands with at least one product on sale.
 */
final class ListBrands
{
    public function __invoke(): AnonymousResourceCollection
    {
        return BrandResource::collection(
            Brand::query()->whereHas('products', fn ($q) => $q->visible())->orderBy('name')->get(),
        );
    }
}
