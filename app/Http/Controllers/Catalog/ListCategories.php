<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Catalog\CatalogCache;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

/**
 * The category tree: top-level categories with their sub-categories.
 */
final class ListCategories
{
    public function __invoke(CatalogCache $cache): JsonResponse
    {
        return new JsonResponse($cache->remember('categories', fn (): array => [
            'data' => CategoryResource::collection(
                Category::query()->whereNull('parent_id')->with('children')->orderBy('position')->orderBy('name')->get(),
            )->resolve(),
        ]));
    }
}
