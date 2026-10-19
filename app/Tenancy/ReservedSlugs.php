<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Subdomains a store may not take: they belong to the platform or would
 * confuse customers.
 */
final class ReservedSlugs
{
    public const array ALL = [
        'admin', 'api', 'app', 'assets', 'beautyhub', 'billing', 'blog', 'cdn', 'dashboard', 'docs',
        'help', 'horizon', 'mail', 'pay', 'payments', 'platform', 'shop', 'static', 'status',
        'store', 'stripe', 'support', 'www',
    ];
}
