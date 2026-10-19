<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Pages;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Support\Facades\Route;

/** @var string $central */
$central = config('tenancy.central_domain');

Route::domain($central)->get('/', [Pages::class, 'platform']);

// Every storefront path is the same shell; the JavaScript app routes it.
Route::domain('{tenant}.'.$central)
    ->where(['tenant' => '[a-z0-9-]+'])
    ->middleware(IdentifyTenant::class)
    ->get('/{path?}', [Pages::class, 'storefront'])
    ->where('path', '^(?!api/|up$).*$');
