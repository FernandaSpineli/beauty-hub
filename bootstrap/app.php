<?php

declare(strict_types=1);

use App\Exceptions\ErrorEnvelope;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureStoreManager;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

        // The store must be known before authentication (tokens are stored
        // per store) and before rate limiting (limits are keyed by store).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: IdentifyTenant::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureStoreManager::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ErrorEnvelope::render($e);
        });
    })->create();
