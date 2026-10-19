<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\ForeignKeyGuard;
use App\Support\Slugs;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Categories nest at most two levels deep (Maquiagem > Batons).
 */
final class CategoryController
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->orderBy('position')->orderBy('name')->get(),
        );
    }

    public function store(Request $request): CategoryResource
    {
        $data = $this->validated($request);
        $this->assertParentAllowed($data['parent_id'] ?? null);

        $category = Category::query()->create([
            ...$data,
            'slug' => $data['slug'] ?? Slugs::unique(Category::class, $data['name']),
        ]);

        return new CategoryResource($category);
    }

    public function update(Request $request, Category $category): CategoryResource
    {
        $data = $this->validated($request, $category);

        if (array_key_exists('parent_id', $data)) {
            $this->assertParentAllowed($data['parent_id'], $category);
        }

        $category->update($data);

        return new CategoryResource($category);
    }

    public function destroy(Category $category): Response
    {
        ForeignKeyGuard::delete(
            fn () => $category->delete(),
            'CATEGORY_IN_USE',
            'This category still has products or sub-categories.',
        );

        return response()->noContent();
    }

    private function assertParentAllowed(?string $parentId, ?Category $category = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Category::query()->findOrFail($parentId);
        $hasChildren = $category !== null && $category->children()->exists();

        if ($parent->parent_id !== null || $parent->id === $category?->id || $hasChildren) {
            throw new ApiException(422, 'CATEGORY_TOO_DEEP', 'Categories can only be nested one level deep.');
        }
    }

    /**
     * @return array{name: string, slug?: string, parent_id?: string|null, position?: int}
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        /** @var array{name: string, slug?: string, parent_id?: string|null, position?: int} */
        return $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:80'],
            'slug' => ['sometimes', 'string', 'max:80', 'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/',
                Rule::unique('categories', 'slug')->ignore($category?->id)],
            'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('categories', 'id')],
            'position' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);
    }
}
