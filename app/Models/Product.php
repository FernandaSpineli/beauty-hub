<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\SkinTypes;
use App\Catalog\FlushesCatalogCache;
use App\Enums\ProductStatus;
use App\Enums\SkinType;
use App\Tenancy\BelongsToTenant;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $brand_id
 * @property string $category_id
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property string $ingredients
 * @property Collection<int, SkinType> $skin_types
 * @property bool $is_vegan
 * @property bool $is_cruelty_free
 * @property string|null $anvisa_registration
 * @property string|null $image_url
 * @property ProductStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Brand $brand
 * @property-read Category $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProductVariant> $variants
 */
#[Fillable([
    'brand_id', 'category_id', 'name', 'slug', 'description', 'ingredients',
    'skin_types', 'is_vegan', 'is_cruelty_free', 'anvisa_registration', 'image_url', 'status',
])]
final class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use BelongsToTenant, FlushesCatalogCache, HasFactory, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'description' => '',
        'ingredients' => '',
        'skin_types' => '{}',
        'is_vegan' => false,
        'is_cruelty_free' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'skin_types' => SkinTypes::class,
            'is_vegan' => 'boolean',
            'is_cruelty_free' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('name');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', ProductStatus::Active);
    }
}
