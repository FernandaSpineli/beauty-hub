<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

function signUp(array $overrides = []): TestResponse
{
    return test()->postJson('http://'.config('tenancy.central_domain').'/api/v1/stores', [
        'slug' => 'Bella', 'name' => 'Bella Cosméticos', 'owner_name' => 'Bia Lima',
        'owner_email' => 'bia@bella.test', 'password' => 'loja2026', 'password_confirmation' => 'loja2026',
        ...$overrides,
    ]);
}

it('opens a store whose creator becomes its owner, signed in', function (): void {
    $response = signUp()->assertCreated()
        ->assertJsonPath('data.store.slug', 'bella')
        ->assertJsonPath('data.store.url', 'http://bella.beautyhub.localhost:8000/')
        ->assertJsonPath('data.owner.role', 'owner');

    $this->withToken($response->json('data.token'))
        ->getJson($this->storeUrl('bella', 'admin/products'))
        ->assertOk();
});

it('refuses taken, reserved and malformed addresses', function (string $slug): void {
    Tenant::factory()->create(['slug' => 'glow']);

    signUp(['slug' => $slug])->assertUnprocessable()->assertJsonPath('error.details.0.field', 'slug');
})->with(['glow', 'www', 'admin', 'horizon', 'a', '-bella', 'bella_', 'bélla']);

it('is only available on the platform domain', function (): void {
    Tenant::factory()->create(['slug' => 'glow']);

    $this->postJson($this->storeUrl('glow', 'stores'), [])->assertNotFound();
});

it('lets operators suspend and reactivate a store from the command line', function (): void {
    signUp();

    Artisan::call('stores', ['action' => 'suspend', 'slug' => 'bella']);
    $this->getJson($this->storeUrl('bella', 'store'))->assertForbidden()->assertJsonPath('error.code', 'STORE_SUSPENDED');

    Artisan::call('stores', ['action' => 'activate', 'slug' => 'bella']);
    $this->getJson($this->storeUrl('bella', 'store'))->assertOk();

    Artisan::call('stores', ['action' => 'list']);
    expect(Artisan::output())->toContain('bella')->toContain('active');
    expect(asStore(Tenant::query()->where('slug', 'bella')->firstOrFail(), fn () => User::query()->count()))->toBe(1);
});
