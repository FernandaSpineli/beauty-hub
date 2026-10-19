<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->bella = Tenant::factory()->create(['slug' => 'bella']);

    asStore($this->bella, function (): void {
        $brands = [
            'natura' => Brand::factory()->create(['name' => 'Natura', 'slug' => 'natura']),
            'vult' => Brand::factory()->create(['name' => 'Vult', 'slug' => 'vult']),
            'sallve' => Brand::factory()->create(['name' => 'Sallve', 'slug' => 'sallve']),
        ];
        $skincare = Category::factory()->create(['name' => 'Skincare', 'slug' => 'skincare']);
        $serums = Category::factory()->create(['name' => 'Séruns', 'slug' => 'seruns', 'parent_id' => $skincare->id]);
        $makeup = Category::factory()->create(['name' => 'Maquiagem', 'slug' => 'maquiagem']);

        $make = function (string $name, string $brand, Category $category, array $prices, array $extra = []) use ($brands): Product {
            $product = Product::factory()->create([
                'name' => $name, 'slug' => Str::slug($name), 'brand_id' => $brands[$brand]->id,
                'category_id' => $category->id, ...$extra,
            ]);
            foreach ($prices as $i => [$price, $hex]) {
                ProductVariant::factory()->create(['product_id' => $product->id, 'price_cents' => $price, 'shade_hex' => $hex, 'name' => "Tom {$i}"]);
            }

            return $product;
        };

        $make('Hidratante Facial Ácido Hialurônico', 'sallve', $skincare, [[8990, null]], ['skin_types' => ['dry', 'sensitive'], 'is_vegan' => true, 'is_cruelty_free' => true]);
        $make('Sérum Vitamina C', 'sallve', $serums, [[11990, null]], ['skin_types' => ['oily'], 'is_cruelty_free' => true]);
        $make('Batom Líquido Matte', 'vult', $makeup, [[2990, '#c98b85'], [2990, '#8e3b46']], ['is_vegan' => true]);
        $make('Base Líquida Matte', 'vult', $makeup, [[5490, '#e8c4a8'], [5990, '#a4785a']]);
        $make('Perfume Essencial', 'natura', $makeup, [[24990, null]]);
        $make('Rascunho Secreto', 'natura', $makeup, [[1000, null]], ['status' => ProductStatus::Draft]);

        // Only inactive variants: nothing to sell.
        $sold = $make('Paleta Esgotada', 'vult', $makeup, [[3990, null]]);
        $sold->variants()->update(['is_active' => false]);
    });

    // Another store's catalog must never show up.
    asStore(Tenant::factory()->create(['slug' => 'glow']), fn () => ProductVariant::factory()->create());
});

function names(TestResponse $response): array
{
    return array_column($response->assertOk()->json('data'), 'name');
}

it('lists the store\'s products on sale, with lowest price and shades', function (): void {
    $response = $this->getJson($this->storeUrl('bella', 'products?sort=name'));

    expect(names($response))->toBe([
        'Base Líquida Matte', 'Batom Líquido Matte', 'Hidratante Facial Ácido Hialurônico', 'Perfume Essencial', 'Sérum Vitamina C',
    ]);
    $base = collect($response->json('data'))->firstWhere('name', 'Base Líquida Matte');
    expect($base['from_price_cents'])->toBe(5490)
        ->and($base['shades'])->toBe([['name' => 'Tom 0', 'hex' => '#e8c4a8'], ['name' => 'Tom 1', 'hex' => '#a4785a']])
        ->and($base['brand'])->toBe(['name' => 'Vult', 'slug' => 'vult']);
});

it('forgives typos and accents in search', function (string $query, string $expected): void {
    expect(names($this->getJson($this->storeUrl('bella', 'products?q='.urlencode($query)))))->toContain($expected);
})->with([
    ['hidratnte', 'Hidratante Facial Ácido Hialurônico'],
    ['acido hialuronico', 'Hidratante Facial Ácido Hialurônico'],
    ['SERUM', 'Sérum Vitamina C'],
    ['sallve', 'Sérum Vitamina C'],
    ['batom matte', 'Batom Líquido Matte'],
    ['ba', 'Base Líquida Matte'],
]);

it('requires every search word to match', function (): void {
    expect(names($this->getJson($this->storeUrl('bella', 'products?q=matte+batom'))))->toBe(['Batom Líquido Matte']);
    expect(names($this->getJson($this->storeUrl('bella', 'products?q=perfume+serum'))))->toBe([]);
});

it('ranks closer matches first', function (): void {
    expect(names($this->getJson($this->storeUrl('bella', 'products?q=base+matte')))[0])->toBe('Base Líquida Matte');
});

it('filters by category including sub-categories', function (): void {
    expect(names($this->getJson($this->storeUrl('bella', 'products?category=skincare&sort=name'))))
        ->toBe(['Hidratante Facial Ácido Hialurônico', 'Sérum Vitamina C']);
    expect(names($this->getJson($this->storeUrl('bella', 'products?category=seruns'))))
        ->toBe(['Sérum Vitamina C']);
});

it('filters by brand, skin type and ethics', function (string $query, array $expected): void {
    expect(names($this->getJson($this->storeUrl('bella', "products?sort=name&{$query}"))))->toBe($expected);
})->with([
    'two brands' => ['brand=natura,sallve', ['Hidratante Facial Ácido Hialurônico', 'Perfume Essencial', 'Sérum Vitamina C']],
    'skin type' => ['skin_type=oily', ['Sérum Vitamina C']],
    'vegan' => ['vegan=1', ['Batom Líquido Matte', 'Hidratante Facial Ácido Hialurônico']],
    'vegan and cruelty-free' => ['vegan=1&cruelty_free=1', ['Hidratante Facial Ácido Hialurônico']],
]);

it('filters by price of any variant on sale', function (): void {
    // The second shade of the foundation (59,90) is in range, the first is not.
    expect(names($this->getJson($this->storeUrl('bella', 'products?min_price=5500&max_price=9000&sort=name'))))
        ->toBe(['Base Líquida Matte', 'Hidratante Facial Ácido Hialurônico']);
});

it('sorts by price', function (): void {
    expect(names($this->getJson($this->storeUrl('bella', 'products?sort=price_asc'))))
        ->toBe(['Batom Líquido Matte', 'Base Líquida Matte', 'Hidratante Facial Ácido Hialurônico', 'Sérum Vitamina C', 'Perfume Essencial']);
    expect(names($this->getJson($this->storeUrl('bella', 'products?sort=price_desc')))[0])->toBe('Perfume Essencial');
});

it('paginates', function (): void {
    $this->getJson($this->storeUrl('bella', 'products?per_page=2&page=3'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.last_page', 3);
});

it('validates the filters', function (string $query, string $field): void {
    $this->getJson($this->storeUrl('bella', "products?{$query}"))
        ->assertUnprocessable()
        ->assertJsonPath('error.details.0.field', $field);
})->with([
    ['min_price=500&max_price=100', 'max_price'],
    ['sort=random', 'sort'],
    ['skin_type=green', 'skin_type'],
    ['per_page=500', 'per_page'],
]);

it('shows a product with its shades on sale', function (): void {
    $this->getJson($this->storeUrl('bella', 'products/batom-liquido-matte'))
        ->assertOk()
        ->assertJsonPath('data.brand.name', 'Vult')
        ->assertJsonCount(2, 'data.variants');
});

it('hides drafts and other stores\' products', function (): void {
    $this->getJson($this->storeUrl('bella', 'products/rascunho-secreto'))->assertNotFound();

    $glowSlug = asStore(Tenant::query()->where('slug', 'glow')->firstOrFail(), fn () => Product::query()->value('slug'));
    $this->getJson($this->storeUrl('bella', "products/{$glowSlug}"))->assertNotFound();
});

it('lists the category tree and brands with products on sale', function (): void {
    $this->getJson($this->storeUrl('bella', 'categories'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Maquiagem')
        ->assertJsonPath('data.1.name', 'Skincare')
        ->assertJsonPath('data.1.children.0.name', 'Séruns');

    expect(array_column($this->getJson($this->storeUrl('bella', 'brands'))->json('data'), 'name'))
        ->toBe(['Natura', 'Sallve', 'Vult']);
});
