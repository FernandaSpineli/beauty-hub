<?php

declare(strict_types=1);

namespace App\Models;

use App\Catalog\FlushesCatalogCache;
use App\Tenancy\BelongsToTenant;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A sellable shade or size of a product. Prices are in centavos (BRL).
 *
 * @property string $id
 * @property string $product_id
 * @property string $sku
 * @property string $name
 * @property string|null $shade_hex
 * @property int $price_cents
 * @property int|null $compare_at_price_cents
 * @property bool $is_active
 * @property int $position
 * @property-read Product $product
 * @property-read VariantStock|null $stock
 */
#[Fillable(['sku', 'name', 'shade_hex', 'price_cents', 'compare_at_price_cents', 'is_active', 'position'])]
final class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use BelongsToTenant, FlushesCatalogCache, HasFactory, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'compare_at_price_cents' => 'integer',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasOne<VariantStock, $this>
     */
    public function stock(): HasOne
    {
        return $this->hasOne(VariantStock::class, 'variant_id');
    }

    /**
     * @return HasMany<StockLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(StockLot::class, 'variant_id');
    }

    /**
     * Components of a kit; empty for a regular variant.
     *
     * @return BelongsToMany<ProductVariant, $this>
     */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'kit_components', 'kit_variant_id', 'component_variant_id')
            ->withPivot('quantity');
    }
}
