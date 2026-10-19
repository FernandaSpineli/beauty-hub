<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
});

it('lets owners and staff manage the catalog', function (UserRole $role): void {
    $this->withToken(tokenFor($this->store, $role))
        ->postJson($this->storeUrl('bella', 'admin/brands'), ['name' => 'Quem Disse Berenice'])
        ->assertCreated();
})->with([UserRole::Owner, UserRole::Staff]);

it('forbids customers and requires a token', function (): void {
    $this->withToken(tokenFor($this->store, UserRole::Customer))
        ->getJson($this->storeUrl('bella', 'admin/products'))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');

    app('auth')->forgetGuards();
    $this->withoutToken()->getJson($this->storeUrl('bella', 'admin/products'))->assertUnauthorized();
});

it('protects every admin route', function (): void {
    // Read from the router, so new admin routes are covered automatically.
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin/'))
        ->flatMap(fn ($route): array => array_map(
            fn (string $method): array => [$method, preg_replace('/\{\w+\}/', '0199f0c4-0000-7000-8000-000000000001', substr($route->uri(), strlen('api/v1/')))],
            array_diff($route->methods(), ['HEAD']),
        ));
    expect($routes->count())->toBeGreaterThan(15);

    $customer = tokenFor($this->store, UserRole::Customer);
    foreach ($routes as [$method, $path]) {
        app('auth')->forgetGuards();
        $this->withoutToken()->json($method, $this->storeUrl('bella', $path))->assertUnauthorized();

        app('auth')->forgetGuards();
        $this->withToken($customer)->json($method, $this->storeUrl('bella', $path))->assertForbidden();
    }
});
