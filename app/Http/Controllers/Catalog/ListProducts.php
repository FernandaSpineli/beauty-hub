<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Catalog\ProductSearch;
use App\Enums\SkinType;
use App\Http\Resources\ProductSummaryResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * GET /products?q=&category=&brand=a,b&skin_type=&vegan=1&cruelty_free=1&in_stock=1
 *   &min_price=&max_price=&sort=&page=&per_page=
 */
final class ListProducts
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'category' => ['sometimes', 'string', 'max:80'],
            'brand' => ['sometimes', 'string', 'max:400'],
            'skin_type' => ['sometimes', Rule::enum(SkinType::class)],
            'vegan' => ['sometimes', 'boolean'],
            'cruelty_free' => ['sometimes', 'boolean'],
            'in_stock' => ['sometimes', 'boolean'],
            'min_price' => ['sometimes', 'integer', 'min:0'],
            'max_price' => ['sometimes', 'integer', 'min:0', 'gte:min_price'],
            'sort' => ['sometimes', Rule::in(ProductSearch::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ]);

        // Query string values arrive as strings: read them typed.
        $query = $request->filled('q') ? $request->string('q')->toString() : null;
        $search = new ProductSearch(
            query: $query,
            category: $request->filled('category') ? $request->string('category')->toString() : null,
            brands: array_values(array_filter(explode(',', $request->string('brand')->toString()))),
            skinType: $request->enum('skin_type', SkinType::class),
            veganOnly: $request->boolean('vegan'),
            crueltyFreeOnly: $request->boolean('cruelty_free'),
            inStockOnly: $request->boolean('in_stock'),
            minPriceCents: $request->filled('min_price') ? $request->integer('min_price') : null,
            maxPriceCents: $request->filled('max_price') ? $request->integer('max_price') : null,
            sort: $request->string('sort', $query !== null ? 'relevance' : 'newest')->toString(),
            perPage: $request->integer('per_page', 24),
        );

        return ProductSummaryResource::collection($search->paginate());
    }
}
