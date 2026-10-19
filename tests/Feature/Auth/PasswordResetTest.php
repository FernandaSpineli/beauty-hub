<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->bella = Tenant::factory()->create(['slug' => 'bella', 'name' => 'Bella Cosméticos']);
    $this->ana = storeUser($this->bella, ['email' => 'ana@example.com']);
});

/**
 * Asks for a reset link and returns the token from the email.
 */
function requestResetToken(string $store, User $user): string
{
    test()->postJson(test()->storeUrl($store, 'auth/password/forgot'), ['email' => $user->email])->assertAccepted();

    $token = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token, $user): bool {
        $url = $notification->toMail($user)->actionUrl;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['token'];

        return true;
    });

    return $token;
}

it('emails a link to the store\'s own reset page, in the store\'s name', function (): void {
    requestResetToken('bella', $this->ana);

    Notification::assertSentTo($this->ana, ResetPasswordNotification::class, function ($notification): bool {
        $mail = $notification->toMail($this->ana);

        return str_starts_with($mail->actionUrl, 'http://bella.beautyhub.localhost:8000/redefinir-senha?token=')
            && $mail->from[1] === 'Bella Cosméticos'
            && str_contains($mail->subject, 'Bella Cosméticos');
    });
});

it('answers the same for unknown emails and sends nothing', function (): void {
    $this->postJson($this->storeUrl('bella', 'auth/password/forgot'), ['email' => 'who@example.com'])
        ->assertAccepted();

    Notification::assertNothingSent();
});

it('sets a new password and signs out every device', function (): void {
    $old = $this->postJson($this->storeUrl('bella', 'auth/login'), ['email' => 'ana@example.com', 'password' => 'password123'])->json('data.token');
    $token = requestResetToken('bella', $this->ana);

    $this->postJson($this->storeUrl('bella', 'auth/password/reset'), [
        'email' => 'ana@example.com', 'token' => $token,
        'password' => 'novasenha1', 'password_confirmation' => 'novasenha1',
    ])->assertNoContent();

    app('auth')->forgetGuards();
    $this->withToken($old)->getJson($this->storeUrl('bella', 'me'))->assertUnauthorized();
    $this->postJson($this->storeUrl('bella', 'auth/login'), ['email' => 'ana@example.com', 'password' => 'password123'])->assertUnauthorized();
    $this->postJson($this->storeUrl('bella', 'auth/login'), ['email' => 'ana@example.com', 'password' => 'novasenha1'])->assertOk();
});

it('rejects an invalid token', function (): void {
    $this->postJson($this->storeUrl('bella', 'auth/password/reset'), [
        'email' => 'ana@example.com', 'token' => 'forged',
        'password' => 'novasenha1', 'password_confirmation' => 'novasenha1',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');
});

it('does not accept one store\'s reset token at another store', function (): void {
    $glow = Tenant::factory()->create(['slug' => 'glow']);
    storeUser($glow, ['email' => 'ana@example.com']);
    $token = requestResetToken('bella', $this->ana);

    $this->postJson($this->storeUrl('glow', 'auth/password/reset'), [
        'email' => 'ana@example.com', 'token' => $token,
        'password' => 'novasenha1', 'password_confirmation' => 'novasenha1',
    ])->assertUnprocessable();
});
