<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Absolute URLs on a store's subdomain, for emails and payment redirects.
 * Scheme and port come from APP_URL.
 */
final class StoreUrl
{
    /**
     * @param  array<string, string>  $query
     */
    public static function to(Tenant $tenant, string $path = '', array $query = []): string
    {
        $appUrl = parse_url(config()->string('app.url'));
        $scheme = $appUrl['scheme'] ?? 'https';
        $port = isset($appUrl['port']) ? ':'.$appUrl['port'] : '';
        $central = config()->string('tenancy.central_domain');

        $url = "{$scheme}://{$tenant->slug}.{$central}{$port}/".ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
