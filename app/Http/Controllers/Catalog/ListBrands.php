<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Catalog\CatalogCache;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;

/**
 * Brands with at least one product on sale.
 */
final class ListBrands
{
    public function __invoke(CatalogCache $cache): JsonResponse
    {
        return new JsonResponse($cache->remember('brands', fn (): array => [
            'data' => BrandResource::collection(
                Brand::query()->whereHas('products', fn ($q) => $q->visible())->orderBy('name')->get(),
            )->resolve(),
        ]));
    }
}
