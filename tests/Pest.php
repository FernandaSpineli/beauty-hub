<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Support\Facades\DB;
use Tests\FeatureTestCase;
use Tests\TestCase;

pest()->extend(FeatureTestCase::class)->in('Feature');

// Unit tests need no database; Integration tests manage their own state
// (e.g. reconnecting, which a wrapping transaction would not survive).
pest()->extend(TestCase::class)->in('Unit', 'Integration');

/**
 * A throwaway tenant-scoped table, created by the schema owner like any
 * migration would and isolated with the same helper.
 */
function createProbeTable(): void
{
    $owner = DB::connection('owner');
    $owner->statement('DROP TABLE IF EXISTS rls_probes');
    $owner->statement('CREATE TABLE rls_probes (id bigserial PRIMARY KEY, tenant_id uuid NOT NULL REFERENCES tenants(id), note text NOT NULL)');
    DB::usingConnection('owner', fn () => TenantIsolation::enable('rls_probes'));
}
