<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ProductStatus;
use App\Http\Requests\Admin\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Support\Slugs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Products are never deleted, since orders refer to them: set the status
 * to "archived" to stop selling one.
 */
final class ProductController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(ProductStatus::class)]]);

        $products = Product::query()
            ->with(['brand', 'category', 'variants'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(25);

        return ProductResource::collection($products);
    }

    public function store(ProductRequest $request): ProductResource
    {
        /** @var array<string, mixed>&array{name: string, slug?: string} $data */
        $data = $request->validated();
        $data['slug'] ??= Slugs::unique(Product::class, $data['name']);

        $product = Product::query()->create($data);

        return new ProductResource($product->load(['brand', 'category', 'variants']));
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(['brand', 'category', 'variants']));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $product->update($data);

        return new ProductResource($product->load(['brand', 'category', 'variants']));
    }
}
