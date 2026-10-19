<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

it('serves the store behind the subdomain', function (): void {
    Tenant::factory()->create(['slug' => 'bella', 'name' => 'Bella Cosméticos']);

    $this->getJson($this->storeUrl('bella', 'store'))
        ->assertOk()
        ->assertExactJson(['data' => ['slug' => 'bella', 'name' => 'Bella Cosméticos', 'theme_color' => '#b0476b']]);
});

it('answers 404 for a store that does not exist', function (): void {
    $this->getJson($this->storeUrl('nope', 'store'))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'STORE_NOT_FOUND');
});

it('answers 403 for a suspended store', function (): void {
    Tenant::factory()->suspended()->create(['slug' => 'closed']);

    $this->getJson($this->storeUrl('closed', 'store'))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'STORE_SUSPENDED');
});

it('does not serve store routes on the central domain', function (): void {
    $this->getJson('http://'.config('tenancy.central_domain').'/api/v1/store')->assertNotFound();
});

it('clears the tenant when the request ends', function (): void {
    Tenant::factory()->create(['slug' => 'bella']);

    $this->getJson($this->storeUrl('bella', 'store'))->assertOk();

    expect(app(TenantContext::class)->current())->toBeNull()
        ->and(DB::scalar("SELECT current_setting('app.tenant_id', true)"))->toBe('');
});
