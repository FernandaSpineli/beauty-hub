<?php

declare(strict_types=1);

use App\Enums\SkinType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->bella = Tenant::factory()->create();
    $this->glow = Tenant::factory()->create();
});

it('refuses a product that points at another store\'s brand', function (): void {
    $glowBrand = asStore($this->glow, fn () => Brand::factory()->create());

    asStore($this->bella, function () use ($glowBrand): void {
        $category = Category::factory()->create();

        // Row-level security does not apply to foreign key checks; the
        // composite (tenant_id, brand_id) key is what stops this.
        expect(fn () => DB::transaction(fn () => Product::factory()->create([
            'brand_id' => $glowBrand->id,
            'category_id' => $category->id,
        ])))->toThrow(QueryException::class, 'products_tenant_id_brand_id_fkey');
    });
});

it('refuses a sub-category under another store\'s category', function (): void {
    $glowCategory = asStore($this->glow, fn () => Category::factory()->create());

    asStore($this->bella, function () use ($glowCategory): void {
        expect(fn () => DB::transaction(fn () => Category::factory()->create(['parent_id' => $glowCategory->id])))
            ->toThrow(QueryException::class, 'categories_tenant_id_parent_id_fkey');
    });
});

it('stores skin types as a PostgreSQL array', function (): void {
    $product = asStore($this->bella, fn () => Product::factory()->create([
        'skin_types' => [SkinType::Dry, 'sensitive', SkinType::Dry],
    ]));

    asStore($this->bella, function () use ($product): void {
        expect(DB::table('products')->where('id', $product->id)->value('skin_types'))->toBe('{dry,sensitive}')
            ->and($product->fresh()?->skin_types->all())->toBe([SkinType::Dry, SkinType::Sensitive]);
    });
});

it('enforces prices and sale prices', function (array $attributes): void {
    asStore($this->bella, function () use ($attributes): void {
        expect(fn () => DB::transaction(fn () => ProductVariant::factory()->create($attributes)))
            ->toThrow(QueryException::class, 'check constraint');
    });
})->with([
    'zero price' => [['price_cents' => 0]],
    'compare-at not above price' => [['price_cents' => 5000, 'compare_at_price_cents' => 5000]],
    'malformed shade' => [['shade_hex' => 'red']],
    'lowercase sku' => [['sku' => 'bh-001']],
]);

it('normalises search text without accents or case', function (): void {
    expect(DB::scalar("SELECT search_normalize('Hidratante Labial Açaí')"))->toBe('hidratante labial acai');
});
