<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella']);
    $this->valid = [
        'name' => 'Ana Souza',
        'email' => '  Ana@Example.com ',
        'password' => 'batom2026',
        'password_confirmation' => 'batom2026',
    ];
});

it('creates a customer account and signs it in', function (): void {
    $response = $this->postJson($this->storeUrl('bella', 'auth/register'), $this->valid)
        ->assertCreated()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'ana@example.com')
        ->assertJsonPath('data.user.role', 'customer');

    expect($response->json('data.token'))->toMatch('/\A[0-9a-f-]{36}\|bh_\w+\z/');

    $this->withToken($response->json('data.token'))
        ->getJson($this->storeUrl('bella', 'me'))
        ->assertOk()
        ->assertJsonPath('data.name', 'Ana Souza');
});

it('ignores a role sent by the client', function (): void {
    $this->postJson($this->storeUrl('bella', 'auth/register'), [...$this->valid, 'role' => 'owner'])
        ->assertCreated()
        ->assertJsonPath('data.user.role', 'customer');
});

it('rejects an email already registered at the same store', function (): void {
    storeUser($this->store, ['email' => 'ana@example.com']);

    $this->postJson($this->storeUrl('bella', 'auth/register'), $this->valid)
        ->assertUnprocessable()
        ->assertJsonPath('error.details.0.field', 'email');
});

it('lets the same email have an account at another store', function (): void {
    $glow = Tenant::factory()->create(['slug' => 'glow']);
    storeUser($glow, ['email' => 'ana@example.com']);

    $this->postJson($this->storeUrl('bella', 'auth/register'), $this->valid)->assertCreated();

    expect(asStore($this->store, fn () => User::query()->count()))->toBe(1)
        ->and(asStore($glow, fn () => User::query()->count()))->toBe(1);
});

it('validates the fields', function (array $override, string $field): void {
    $this->postJson($this->storeUrl('bella', 'auth/register'), [...$this->valid, ...$override])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.0.field', $field);
})->with([
    'weak password' => [['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'], 'password'],
    'confirmation mismatch' => [['password_confirmation' => 'other2026'], 'password'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'missing name' => [['name' => ''], 'name'],
]);
