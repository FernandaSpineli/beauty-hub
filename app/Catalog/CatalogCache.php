<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Caches storefront data that every page asks for and that rarely changes
 * (category tree, brand list). Keys and the tag are always namespaced by the
 * current store, so one store can never be served another's cached data.
 * Catalog writes flush the store's tag; the TTL bounds staleness for writes
 * that bypass model events (mass updates).
 */
final readonly class CatalogCache
{
    public function __construct(private TenantContext $context) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(string $key, Closure $callback): mixed
    {
        $store = $this->context->require()->id;

        return $this->tagged($store)->remember(
            "catalog:{$store}:{$key}",
            config()->integer('cache.catalog_ttl_seconds'),
            $callback,
        );
    }

    public function flush(): void
    {
        $store = $this->context->id();

        if ($store !== null) {
            $this->tagged($store)->flush();
        }
    }

    private function tagged(string $store): Repository
    {
        return Cache::tags(["catalog:{$store}"]);
    }
}
