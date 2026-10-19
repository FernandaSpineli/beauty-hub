<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    $this->withToken(tokenFor($this->store));
    $this->url = fn (string $path): string => $this->storeUrl('bella', 'admin/'.$path);
});

it('builds a product with shades from scratch', function (): void {
    $brand = $this->postJson(($this->url)('brands'), ['name' => 'Boca Rosa Beauty'])
        ->assertCreated()->assertJsonPath('data.slug', 'boca-rosa-beauty')->json('data');
    $makeup = $this->postJson(($this->url)('categories'), ['name' => 'Maquiagem'])->assertCreated()->json('data');
    $lips = $this->postJson(($this->url)('categories'), ['name' => 'Batons', 'parent_id' => $makeup['id']])
        ->assertCreated()->json('data');

    $product = $this->postJson(($this->url)('products'), [
        'name' => 'Batom Líquido Matte',
        'brand_id' => $brand['id'],
        'category_id' => $lips['id'],
        'skin_types' => ['dry', 'normal'],
        'is_vegan' => true,
        'is_cruelty_free' => true,
        'anvisa_registration' => '25351.123456',
        'status' => 'active',
    ])->assertCreated()
        ->assertJsonPath('data.slug', 'batom-liquido-matte')
        ->assertJsonPath('data.skin_types', ['dry', 'normal'])
        ->assertJsonPath('data.brand.name', 'Boca Rosa Beauty')
        ->json('data');

    $this->postJson(($this->url)("products/{$product['id']}/variants"), [
        'sku' => 'BRB-BLM-NUDE', 'name' => 'Nude Rosado', 'shade_hex' => '#c98b85',
        'price_cents' => 4990, 'compare_at_price_cents' => 5990,
    ])->assertCreated()->assertJsonPath('data.shade_hex', '#c98b85');

    $this->getJson(($this->url)("products/{$product['id']}"))
        ->assertOk()
        ->assertJsonCount(1, 'data.variants')
        ->assertJsonPath('data.variants.0.price_cents', 4990);
});

it('makes slugs unique within the store', function (): void {
    $this->postJson(($this->url)('brands'), ['name' => 'Natura'])->assertJsonPath('data.slug', 'natura');
    $this->postJson(($this->url)('brands'), ['name' => 'NATURA'])->assertJsonPath('data.slug', 'natura-2');

    $this->postJson(($this->url)('brands'), ['name' => 'Outra', 'slug' => 'natura'])
        ->assertUnprocessable()->assertJsonPath('error.details.0.field', 'slug');
});

it('treats another store\'s ids as not found', function (): void {
    $glow = Tenant::factory()->create();
    [$brand, $category, $product, $variant] = asStore($glow, function (): array {
        $variant = ProductVariant::factory()->create();

        return [$variant->product->brand, $variant->product->category, $variant->product, $variant];
    });

    $this->patchJson(($this->url)("brands/{$brand->id}"), ['name' => 'x'])->assertNotFound();
    $this->deleteJson(($this->url)("categories/{$category->id}"))->assertNotFound();
    $this->getJson(($this->url)("products/{$product->id}"))->assertNotFound();
    $this->patchJson(($this->url)("variants/{$variant->id}"), ['price_cents' => 1])->assertNotFound();

    $ownCategory = asStore($this->store, fn () => Category::factory()->create());
    $this->postJson(($this->url)('products'), [
        'name' => 'Sneaky', 'brand_id' => $brand->id, 'category_id' => $ownCategory->id,
    ])->assertUnprocessable()->assertJsonPath('error.details.0.field', 'brand_id');
});

it('refuses to delete a brand or category that is still in use', function (): void {
    $product = asStore($this->store, fn () => Product::factory()->create());

    $this->deleteJson(($this->url)("brands/{$product->brand_id}"))
        ->assertConflict()->assertJsonPath('error.code', 'BRAND_IN_USE');
    $this->deleteJson(($this->url)("categories/{$product->category_id}"))
        ->assertConflict()->assertJsonPath('error.code', 'CATEGORY_IN_USE');

    $unused = asStore($this->store, fn () => Brand::factory()->create());
    $this->deleteJson(($this->url)("brands/{$unused->id}"))->assertNoContent();
});

it('nests categories only one level deep', function (): void {
    [$root, $child] = asStore($this->store, function (): array {
        $root = Category::factory()->create();

        return [$root, Category::factory()->create(['parent_id' => $root->id])];
    });

    $this->postJson(($this->url)('categories'), ['name' => 'Neta', 'parent_id' => $child->id])
        ->assertUnprocessable()->assertJsonPath('error.code', 'CATEGORY_TOO_DEEP');
    $this->patchJson(($this->url)("categories/{$root->id}"), ['parent_id' => $root->id])
        ->assertUnprocessable()->assertJsonPath('error.code', 'CATEGORY_TOO_DEEP');

    $other = asStore($this->store, fn () => Category::factory()->create());
    $this->patchJson(($this->url)("categories/{$root->id}"), ['parent_id' => $other->id])
        ->assertUnprocessable()->assertJsonPath('error.code', 'CATEGORY_TOO_DEEP');
});

it('keeps the compare-at price above the price', function (): void {
    $variant = asStore($this->store, fn () => ProductVariant::factory()->create([
        'price_cents' => 4990, 'compare_at_price_cents' => 5990,
    ]));

    $this->patchJson(($this->url)("variants/{$variant->id}"), ['compare_at_price_cents' => 3000])
        ->assertUnprocessable()->assertJsonPath('error.details.0.field', 'compare_at_price_cents');

    // A new price above the old "was" price ends the sale.
    $this->patchJson(($this->url)("variants/{$variant->id}"), ['price_cents' => 6490])
        ->assertOk()
        ->assertJsonPath('data.price_cents', 6490)
        ->assertJsonPath('data.compare_at_price_cents', null);
});

it('validates product fields', function (array $payload, string $field): void {
    $this->postJson(($this->url)('products'), $payload)
        ->assertUnprocessable()
        ->assertJsonFragment(['field' => $field]);
})->with([
    'unknown skin type' => [['skin_types' => ['purple']], 'skin_types.0'],
    'bad ANVISA number' => [['anvisa_registration' => 'abc'], 'anvisa_registration'],
    'missing brand' => [['name' => 'x'], 'brand_id'],
    'non-http image' => [['image_url' => 'javascript:alert(1)'], 'image_url'],
]);

it('lists products of every status, newest first, paginated', function (): void {
    asStore($this->store, function (): void {
        Product::factory()->create(['name' => 'Rascunho', 'status' => 'draft']);
        Product::factory()->create(['name' => 'Ativo']);
    });

    $this->getJson(($this->url)('products'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
    $this->getJson(($this->url)('products?status=draft'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Rascunho');
});
