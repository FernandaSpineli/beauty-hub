<?php

declare(strict_types=1);

use App\Models\Tenant;

beforeEach(function (): void {
    Tenant::factory()->create(['slug' => 'bella']);
    $register = $this->postJson($this->storeUrl('bella', 'auth/register'), [
        'name' => 'Ana', 'email' => 'ana@example.com',
        'password' => 'batom2026', 'password_confirmation' => 'batom2026',
    ]);
    $this->token = $register->json('data.token');
});

it('updates the name but not the email', function (): void {
    $this->withToken($this->token)
        ->patchJson($this->storeUrl('bella', 'me'), ['name' => 'Ana Souza', 'email' => 'new@example.com'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ana Souza')
        ->assertJsonPath('data.email', 'ana@example.com');
});

it('changes the password and signs out the other devices only', function (): void {
    $other = $this->postJson($this->storeUrl('bella', 'auth/login'), ['email' => 'ana@example.com', 'password' => 'batom2026'])->json('data.token');

    $this->withToken($this->token)->putJson($this->storeUrl('bella', 'me/password'), [
        'current_password' => 'batom2026',
        'password' => 'perfume2026', 'password_confirmation' => 'perfume2026',
    ])->assertNoContent();

    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($this->storeUrl('bella', 'me'))->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($other)->getJson($this->storeUrl('bella', 'me'))->assertUnauthorized();
});

it('requires the current password', function (): void {
    $this->withToken($this->token)->putJson($this->storeUrl('bella', 'me/password'), [
        'current_password' => 'wrong',
        'password' => 'perfume2026', 'password_confirmation' => 'perfume2026',
    ])->assertUnprocessable()->assertJsonPath('error.details.0.field', 'current_password');
});
