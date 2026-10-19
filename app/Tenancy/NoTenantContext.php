<?php

declare(strict_types=1);

namespace App\Tenancy;

use LogicException;

/**
 * Tenant-scoped data was written outside a tenant context: a programming
 * error, never a client error.
 */
final class NoTenantContext extends LogicException
{
    public function __construct(string $message = 'No tenant is set for this request or job.')
    {
        parent::__construct($message);
    }
}
