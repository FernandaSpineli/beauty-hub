<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The category tree: top-level categories with their sub-categories.
 */
final class ListCategories
{
    public function __invoke(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->whereNull('parent_id')->with('children')->orderBy('position')->orderBy('name')->get(),
        );
    }
}
