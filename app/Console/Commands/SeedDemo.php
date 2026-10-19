<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CouponKind;
use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Models\User;
use App\Stores\OpenStore;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Two demo stores with fictional brands, so the platform can be tried in a
 * browser: shades, lots with different expiry dates (one about to leave
 * sale), a kit and a welcome coupon. Safe to run twice.
 */
#[Signature('demo:seed')]
#[Description('Create two demo stores with catalog, stock, coupon and accounts')]
final class SeedDemo extends Command
{
    public const string PASSWORD = 'demo12345';

    private int $sequence = 0;

    public function handle(OpenStore $openStore, TenantContext $context): int
    {
        foreach ($this->stores() as $slug => $store) {
            if (Tenant::query()->where('slug', $slug)->exists()) {
                $this->line("{$slug}: already there, skipped.");

                continue;
            }

            DB::transaction(function () use ($openStore, $context, $slug, $store): void {
                [$tenant] = $openStore->handle($slug, $store['name'], $store['owner'][0], $store['owner'][1], self::PASSWORD);
                $tenant->update(['theme_color' => $store['color']]);

                $context->run($tenant, function () use ($store): void {
                    $this->account('Equipe Demo', $store['staff'], UserRole::Staff);
                    $this->account('Cliente Demo', $store['customer'], UserRole::Customer);
                    Coupon::query()->create(['code' => 'BEMVINDA10', 'kind' => CouponKind::Percent, 'value' => 10, 'min_subtotal_cents' => 5000]);
                    $this->catalog($store['catalog']);
                });
            });

            $this->info("{$slug}: created.");
        }

        $this->newLine();
        $this->line('Password for every demo account: '.self::PASSWORD);
        foreach ($this->stores() as $slug => $store) {
            $url = 'http://'.$slug.'.'.config()->string('tenancy.central_domain').':'.(parse_url(config()->string('app.url'), PHP_URL_PORT) ?? 80);
            $this->line("  {$url}  owner {$store['owner'][1]} · staff {$store['staff']} · customer {$store['customer']}");
        }

        return self::SUCCESS;
    }

    private function account(string $name, string $email, UserRole $role): void
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);
        $user->role = $role;
        $user->save();
    }

    /**
     * @param  array<string, array<string, list<array{0: string, 1: string, 2: array<string, mixed>, 3: list<array{0: string, 1: int, 2: string|null, 3: list<array{0: int, 1: int}>}>}>>>  $catalog
     */
    private function catalog(array $catalog): void
    {
        $variants = [];
        foreach ($catalog as $parentName => $categories) {
            $parent = Category::query()->create(['name' => $parentName, 'slug' => Str::slug($parentName)]);
            foreach ($categories as $categoryName => $products) {
                $category = Category::query()->create(['name' => $categoryName, 'slug' => Str::slug($categoryName), 'parent_id' => $parent->id]);
                foreach ($products as [$productName, $brandName, $attributes, $shades]) {
                    $brand = Brand::query()->firstOrCreate(['slug' => Str::slug($brandName)], ['name' => $brandName]);
                    $product = Product::query()->create([
                        'name' => $productName, 'slug' => Str::slug($productName), 'brand_id' => $brand->id,
                        'category_id' => $category->id, 'status' => ProductStatus::Active, ...$attributes,
                    ]);
                    foreach ($shades as $position => [$shade, $price, $hex, $lots]) {
                        $variant = $product->variants()->create([
                            'sku' => sprintf('%s-%03d', strtoupper(substr(Str::slug($brandName, ''), 0, 4)), ++$this->sequence),
                            'name' => $shade, 'price_cents' => $price, 'shade_hex' => $hex, 'position' => $position,
                        ]);
                        foreach ($lots as $i => [$months, $quantity]) {
                            StockLot::query()->create([
                                'variant_id' => $variant->id,
                                'lot_code' => 'L'.now()->format('ym').'-'.($i + 1).strtoupper(Str::random(3)),
                                'expires_on' => $months > 0 ? today()->addMonths($months) : today()->addDays(-$months),
                                'quantity_on_hand' => $quantity,
                            ]);
                        }
                        $variants[] = $variant;
                    }
                }
            }
        }

        $this->kit($variants);
    }

    /**
     * A kit made of the first two skincare items, priced below the sum.
     *
     * @param  list<ProductVariant>  $variants
     */
    private function kit(array $variants): void
    {
        $skincare = Category::query()->where('slug', 'cuidados-com-a-pele')->first();
        if ($skincare === null || count($variants) < 2) {
            return;
        }

        $brand = Brand::query()->firstOrCreate(['slug' => 'kits-da-casa'], ['name' => 'Kits da Casa']);
        $kit = Product::query()->create([
            'name' => 'Kit Rotina Noturna', 'slug' => 'kit-rotina-noturna', 'brand_id' => $brand->id,
            'category_id' => $skincare->id, 'status' => ProductStatus::Active, 'description' => 'Sérum + hidratante, com desconto.',
        ]);
        $variant = $kit->variants()->create(['sku' => 'KIT-NOTURNO', 'name' => 'Kit', 'price_cents' => 15990]);
        $variant->components()->sync([$variants[0]->id => ['quantity' => 1], $variants[1]->id => ['quantity' => 1]]);
    }

    /**
     * Lots are [months until expiry, units]; a negative number means days
     * instead, for a lot already inside the minimum shelf life.
     *
     * @return array<string, array{name: string, color: string, owner: array{0: string, 1: string}, staff: string, customer: string, catalog: array<string, array<string, list<array{0: string, 1: string, 2: array<string, mixed>, 3: list<array{0: string, 1: int, 2: string|null, 3: list<array{0: int, 1: int}>}>}>>>}>
     */
    private function stores(): array
    {
        return [
            'bella' => [
                'name' => 'Bella Cosméticos', 'color' => '#b0476b',
                'owner' => ['Bia Lima', 'dona@bella.demo'], 'staff' => 'equipe@bella.demo', 'customer' => 'cliente@bella.demo',
                'catalog' => [
                    'Cuidados com a pele' => [
                        'Séruns' => [
                            ['Sérum Vitamina C 10%', 'Aurora Pele', ['skin_types' => ['normal', 'combination'], 'is_vegan' => true, 'is_cruelty_free' => true, 'ingredients' => 'Aqua, Ascorbic Acid, Glycerin, Ferulic Acid', 'description' => 'Antioxidante para uso diurno, textura leve.'], [['30 ml', 11990, null, [[14, 25], [-12, 6]]]]],
                        ],
                        'Hidratantes' => [
                            ['Hidratante Facial Ácido Hialurônico', 'Aurora Pele', ['skin_types' => ['dry', 'sensitive'], 'is_cruelty_free' => true, 'ingredients' => 'Aqua, Sodium Hyaluronate, Panthenol, Squalane'], [['50 g', 8990, null, [[10, 30]]]]],
                        ],
                    ],
                    'Maquiagem' => [
                        'Batons' => [
                            ['Batom Líquido Matte', 'Flor de Lis', ['is_vegan' => true, 'is_cruelty_free' => true], [
                                ['Nude Rosado', 3990, '#c98b85', [[18, 40]]],
                                ['Vinho', 3990, '#7b2d3b', [[18, 3]]],
                                ['Coral', 3990, '#e2725b', [[-10, 15]]],
                            ]],
                        ],
                        'Bases' => [
                            ['Base Líquida Cobertura Média', 'Flor de Lis', ['skin_types' => ['oily', 'combination']], [
                                ['Clara 10', 6990, '#f1d1b5', [[20, 12]]],
                                ['Média 30', 6990, '#c99a72', [[20, 12]]],
                                ['Escura 50', 6990, '#7d5236', [[20, 8]]],
                            ]],
                        ],
                    ],
                ],
            ],
            'glow' => [
                'name' => 'Glow Skincare', 'color' => '#2f7d6d',
                'owner' => ['Gabi Rocha', 'dona@glow.demo'], 'staff' => 'equipe@glow.demo', 'customer' => 'cliente@glow.demo',
                'catalog' => [
                    'Cuidados com a pele' => [
                        'Limpeza' => [
                            ['Gel de Limpeza Facial', 'Mar Doce', ['skin_types' => ['oily', 'combination'], 'is_vegan' => true, 'is_cruelty_free' => true], [['150 ml', 4990, null, [[16, 50]]]]],
                        ],
                        'Protetores' => [
                            ['Protetor Solar FPS 50', 'Mar Doce', ['skin_types' => ['normal', 'oily', 'sensitive'], 'is_cruelty_free' => true, 'anvisa_registration' => '25351.000001'], [
                                ['Sem cor 40 g', 7990, null, [[12, 40]]],
                                ['Com cor 40 g', 8490, '#d8b08c', [[12, 20]]],
                            ]],
                        ],
                    ],
                    'Corpo' => [
                        'Óleos' => [
                            ['Óleo Corporal de Amêndoas', 'Ervas do Campo', ['is_vegan' => true], [['200 ml', 5990, null, [[24, 18]]]]],
                        ],
                    ],
                ],
            ],
        ];
    }
}
