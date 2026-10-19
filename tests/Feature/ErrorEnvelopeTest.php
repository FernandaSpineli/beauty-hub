<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config(['app.debug' => false]);

    Route::prefix('api/v1/test')->group(function (): void {
        Route::get('domain', fn () => throw new ApiException(409, 'OUT_OF_STOCK', 'Not enough stock.', [['sku' => 'BH-1']]));
        Route::post('validation', fn (Request $r) => $r->validate(['email' => ['required', 'email']]));
        Route::get('unauthenticated', fn () => throw new AuthenticationException);
        Route::get('forbidden', fn () => throw new AuthorizationException);
        Route::get('crash', fn () => throw new RuntimeException('SQLSTATE secret connection details'));
    });
});

it('renders domain errors with their code and details', function (): void {
    $this->getJson('/api/v1/test/domain')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'OUT_OF_STOCK')
        ->assertJsonPath('error.message', 'Not enough stock.')
        ->assertJsonPath('error.details', [['sku' => 'BH-1']]);
});

it('lists every invalid field', function (): void {
    $this->postJson('/api/v1/test/validation', ['email' => 'nope'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.0.field', 'email');
});

it('maps authentication, authorization and missing routes', function (string $uri, int $status, string $code): void {
    $this->getJson($uri)->assertStatus($status)->assertJsonPath('error.code', $code);
})->with([
    ['/api/v1/test/unauthenticated', 401, 'UNAUTHENTICATED'],
    ['/api/v1/test/forbidden', 403, 'FORBIDDEN'],
    ['/api/v1/does-not-exist', 404, 'NOT_FOUND'],
]);

it('hides internal details of unexpected failures', function (): void {
    $response = $this->getJson('/api/v1/test/crash')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'INTERNAL_ERROR');

    expect($response->getContent())->not->toContain('SQLSTATE');
});

it('returns the request id in the header and in the error body', function (): void {
    $response = $this->getJson('/api/v1/does-not-exist');

    $id = $response->headers->get('X-Request-Id');
    expect($id)->toMatch('/\A[0-9a-f]{32}\z/');
    $response->assertJsonPath('error.request_id', $id);
});

it('reuses a well-formed request id from a proxy and replaces a malformed one', function (): void {
    $this->getJson('/api/v1/does-not-exist', ['X-Request-Id' => 'edge-1234abcd'])
        ->assertHeader('X-Request-Id', 'edge-1234abcd');

    $id = $this->getJson('/api/v1/does-not-exist', ['X-Request-Id' => "bad id\n"])
        ->headers->get('X-Request-Id');
    expect($id)->toMatch('/\A[0-9a-f]{32}\z/');
});
