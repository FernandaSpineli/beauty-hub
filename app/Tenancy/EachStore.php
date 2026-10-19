<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Enums\TenantStatus;
use App\Models\Tenant;

/**
 * Runs scheduled work store by store. Tenant-scoped rows are invisible
 * outside a store, so platform-wide commands (Sanctum's token pruning, for
 * one) would silently see nothing.
 */
final readonly class EachStore
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  callable(Tenant): void  $work
     */
    public function run(callable $work): void
    {
        Tenant::query()->where('status', TenantStatus::Active)->orderBy('id')->each(
            fn (Tenant $store) => $this->context->run($store, fn () => $work($store)),
        );
    }
}
