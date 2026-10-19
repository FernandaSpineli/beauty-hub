<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;

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

it('protects every admin route', function (string $method, string $path): void {
    $this->json($method, $this->storeUrl('bella', $path))->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->withToken(tokenFor($this->store, UserRole::Customer))
        ->json($method, $this->storeUrl('bella', $path))
        ->assertForbidden();
})->with(function (): array {
    $id = '0199f0c4-0000-7000-8000-000000000001';

    return [
        ['GET', 'admin/brands'], ['POST', 'admin/brands'], ['PATCH', "admin/brands/{$id}"], ['DELETE', "admin/brands/{$id}"],
        ['GET', 'admin/categories'], ['POST', 'admin/categories'], ['PATCH', "admin/categories/{$id}"], ['DELETE', "admin/categories/{$id}"],
        ['GET', 'admin/products'], ['POST', 'admin/products'], ['GET', "admin/products/{$id}"], ['PATCH', "admin/products/{$id}"],
        ['POST', "admin/products/{$id}/variants"], ['PATCH', "admin/variants/{$id}"], ['DELETE', "admin/variants/{$id}"],
    ];
});
