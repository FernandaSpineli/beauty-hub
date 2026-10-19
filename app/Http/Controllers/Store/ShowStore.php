<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Resources\StoreResource;
use App\Tenancy\TenantContext;

/**
 * Public details of the store behind the current subdomain.
 */
final class ShowStore
{
    public function __invoke(TenantContext $context): StoreResource
    {
        return new StoreResource($context->require());
    }
}
