<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns on row-level security for a tenant-scoped table, from a migration.
 * The policy hides other tenants' rows and rejects writes that would put a
 * row into another tenant. Forcing it means even the table owner is checked.
 */
final class TenantIsolation
{
    public const string POLICY = 'tenant_isolation';

    public static function enable(string $table): void
    {
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid table name [{$table}].");
        }

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(sprintf(
            'CREATE POLICY %s ON %s USING (tenant_id = current_tenant_id()) WITH CHECK (tenant_id = current_tenant_id())',
            self::POLICY,
            $table,
        ));
    }
}
