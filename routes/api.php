<?php

declare(strict_types=1);

use App\Http\Middleware\IdentifyTenant;
use Illuminate\Support\Facades\Route;

/** @var string $central */
$central = config('tenancy.central_domain');

// Store APIs: {tenant}.beautyhub.localhost/api/v1/...
Route::domain('{tenant}.'.$central)
    ->where(['tenant' => '[a-z0-9-]+'])
    ->middleware(IdentifyTenant::class)
    ->group(base_path('routes/tenant.php'));
