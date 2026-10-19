<?php

declare(strict_types=1);

use App\Http\Controllers\Payments\StripeWebhook;
use App\Http\Controllers\Platform\SignUpStore;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Support\Facades\Route;

/** @var string $central */
$central = config('tenancy.central_domain');

// Stripe webhooks, on any host: authenticity comes from the signature, and
// the Stripe CLI forwards to the container's own address in development.
Route::post('stripe/webhook', StripeWebhook::class);

// Platform APIs: beautyhub.localhost/api/v1/...
Route::domain($central)->group(function (): void {
    Route::post('stores', SignUpStore::class)->middleware('throttle:auth');
});

// Store APIs: {tenant}.beautyhub.localhost/api/v1/...
Route::domain('{tenant}.'.$central)
    ->where(['tenant' => '[a-z0-9-]+'])
    ->middleware([IdentifyTenant::class, 'throttle:api'])
    ->group(base_path('routes/tenant.php'));
