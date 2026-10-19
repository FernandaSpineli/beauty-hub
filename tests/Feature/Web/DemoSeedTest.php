<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

it('creates two demo stores with catalog, stock, a kit and accounts, once', function (): void {
    Artisan::call('demo:seed');
    Artisan::call('demo:seed');

    expect(Tenant::query()->pluck('slug')->sort()->values()->all())->toBe(['bella', 'glow'])
        ->and(Artisan::output())->toContain('already there');

    $products = $this->getJson($this->storeUrl('bella', 'products?per_page=48'))->assertOk()->json('data');
    expect(array_column($products, 'name'))->toContain('Batom Líquido Matte', 'Kit Rotina Noturna');

    // The demo includes a shade whose only lot is inside the minimum shelf life.
    $lipstick = $this->getJson($this->storeUrl('bella', 'products/batom-liquido-matte'))->json('data.variants');
    expect(array_column($lipstick, 'in_stock', 'name'))->toBe(['Nude Rosado' => true, 'Vinho' => true, 'Coral' => false]);

    $this->getJson($this->storeUrl('bella', 'products/kit-rotina-noturna'))->assertJsonPath('data.variants.0.in_stock', true);
    $this->postJson($this->storeUrl('glow', 'auth/login'), ['email' => 'dona@glow.demo', 'password' => 'demo12345'])->assertOk();
    // Stores are isolated: Bella's owner is unknown at Glow.
    $this->postJson($this->storeUrl('glow', 'auth/login'), ['email' => 'dona@bella.demo', 'password' => 'demo12345'])->assertUnauthorized();
});
