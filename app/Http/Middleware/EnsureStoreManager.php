<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits the store's owner and staff. Runs before route model binding, so a
 * customer gets 403 for any admin URL instead of learning, from 404s, which
 * IDs exist.
 */
final class EnsureStoreManager
{
    public function handle(Request $request, Closure $next): Response
    {
        Gate::authorize('manage-store');

        /** @var Response */
        return $next($request);
    }
}
