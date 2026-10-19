<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the store from the {tenant} subdomain of tenant routes and makes
 * it the current tenant for the rest of the request.
 */
final readonly class IdentifyTenant
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $slug = $route instanceof Route ? $route->parameter('tenant') : null;
        $route?->forgetParameter('tenant');

        $tenant = is_string($slug) ? Tenant::query()->where('slug', $slug)->first() : null;

        if ($tenant === null) {
            throw new ApiException(404, 'STORE_NOT_FOUND', 'This store does not exist.');
        }
        if (! $tenant->isActive()) {
            throw new ApiException(403, 'STORE_SUSPENDED', 'This store is temporarily unavailable.');
        }

        $this->context->set($tenant);

        try {
            /** @var Response */
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
