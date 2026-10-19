<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->bella = Tenant::factory()->create(['slug' => 'bella']);
    $this->glow = Tenant::factory()->create(['slug' => 'glow']);
    storeUser($this->bella, ['email' => 'ana@example.com']);
});

function login(string $store, string $email = 'ana@example.com', string $password = 'password123'): TestResponse
{
    return test()->postJson(test()->storeUrl($store, 'auth/login'), ['email' => $email, 'password' => $password]);
}

it('issues a token for valid credentials', function (): void {
    $token = login('bella', 'ANA@example.com')->assertOk()->json('data.token');

    $this->withToken($token)->getJson($this->storeUrl('bella', 'me'))
        ->assertOk()
        ->assertJsonPath('data.email', 'ana@example.com');
});

it('gives the same answer for a wrong password and an unknown email', function (): void {
    $wrongPassword = login('bella', password: 'nope')->assertUnauthorized()->json('error');
    $unknownEmail = login('bella', 'who@example.com')->assertUnauthorized()->json('error');

    expect([$wrongPassword['code'], $wrongPassword['message']])
        ->toBe([$unknownEmail['code'], $unknownEmail['message']])
        ->and($wrongPassword['code'])->toBe('INVALID_CREDENTIALS');
});

it('does not know the account at another store', function (): void {
    login('glow')->assertUnauthorized();
});

it('rejects a token at any store other than the one that issued it', function (): void {
    $token = login('bella')->json('data.token');

    $this->withToken($token)->getJson($this->storeUrl('glow', 'me'))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('does not even find a token from another store', function (): void {
    // Sanctum would also fail to load the token's owner (users are isolated
    // too); this checks the token lookup on its own.
    $token = login('bella')->json('data.token');

    expect(asStore($this->glow, fn () => PersonalAccessToken::findToken($token)))->toBeNull()
        ->and(asStore($this->bella, fn () => PersonalAccessToken::findToken($token)))->not->toBeNull();
});

it('rejects expired tokens', function (): void {
    $token = login('bella')->json('data.token');

    $this->travel(config()->integer('sanctum.expiration') + 1)->minutes();

    $this->withToken($token)->getJson($this->storeUrl('bella', 'me'))->assertUnauthorized();
});

it('requires a token for account routes', function (): void {
    $this->getJson($this->storeUrl('bella', 'me'))->assertUnauthorized();
});

it('limits login attempts per email', function (): void {
    config(['rate_limits.login_per_minute' => 3]);

    foreach (range(1, 3) as $_) {
        login('bella', password: 'wrong')->assertUnauthorized();
    }

    login('bella', password: 'wrong')
        ->assertTooManyRequests()
        ->assertJsonPath('error.code', 'RATE_LIMITED');
    login('bella', 'other@example.com', 'wrong')->assertUnauthorized();
});
