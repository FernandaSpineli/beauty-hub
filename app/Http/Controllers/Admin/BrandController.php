<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Resources\BrandResource;
use App\Models\Brand;
use App\Support\ForeignKeyGuard;
use App\Support\Slugs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

final class BrandController
{
    public function index(): AnonymousResourceCollection
    {
        return BrandResource::collection(Brand::query()->orderBy('name')->get());
    }

    public function store(Request $request): BrandResource
    {
        $data = $this->validated($request);
        $name = $data['name'] ?? '';
        $brand = Brand::query()->create([
            'name' => $name,
            'slug' => $data['slug'] ?? Slugs::unique(Brand::class, $name),
        ]);

        return new BrandResource($brand);
    }

    public function update(Request $request, Brand $brand): BrandResource
    {
        $brand->update($this->validated($request, $brand));

        return new BrandResource($brand);
    }

    public function destroy(Brand $brand): Response
    {
        ForeignKeyGuard::delete(fn () => $brand->delete(), 'BRAND_IN_USE', 'This brand still has products.');

        return response()->noContent();
    }

    /**
     * @return array{name?: string, slug?: string}
     */
    private function validated(Request $request, ?Brand $brand = null): array
    {
        /** @var array{name?: string, slug?: string} */
        return $request->validate([
            'name' => [$brand ? 'sometimes' : 'required', 'string', 'max:80'],
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/',
                Rule::unique('brands', 'slug')->ignore($brand?->id)],
        ]);
    }
}
