<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Tenant::factory()->create(['slug' => 'bella']);
    $this->postJson($this->storeUrl('bella', 'auth/register'), [
        'name' => 'Ana', 'email' => 'ana@example.com',
        'password' => 'batom2026', 'password_confirmation' => 'batom2026',
    ]);
    $this->phone = $this->postJson($this->storeUrl('bella', 'auth/login'), ['email' => 'ana@example.com', 'password' => 'batom2026'])->json('data.token');
    $this->laptop = $this->postJson($this->storeUrl('bella', 'auth/login'), ['email' => 'ana@example.com', 'password' => 'batom2026'])->json('data.token');
});

function me(string $token): TestResponse
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->getJson(test()->storeUrl('bella', 'me'));
}

it('signs out only the current device', function (): void {
    $this->withToken($this->phone)->postJson($this->storeUrl('bella', 'auth/logout'))->assertNoContent();

    me($this->phone)->assertUnauthorized();
    me($this->laptop)->assertOk();
});

it('signs out every device', function (): void {
    $this->withToken($this->phone)->postJson($this->storeUrl('bella', 'auth/logout?all=1'))->assertNoContent();

    me($this->phone)->assertUnauthorized();
    me($this->laptop)->assertUnauthorized();
});
