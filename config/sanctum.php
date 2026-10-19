<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    // Bearer tokens only: no first-party cookie sessions.
    'stateful' => [],
    'guard' => [],

    // Minutes a token stays valid after it is issued.
    'expiration' => (int) env('TOKEN_TTL_MINUTES', 60 * 24 * 7),

    // Makes leaked tokens recognisable to secret scanners (gitleaks, GitHub).
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'bh_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
