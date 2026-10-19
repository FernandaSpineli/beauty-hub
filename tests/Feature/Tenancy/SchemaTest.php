<?php

declare(strict_types=1);

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantIsolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Guards against the easy mistake: a new tenant-scoped table or model that
 * forgets its isolation.
 */

it('connects as a role that row-level security applies to', function (): void {
    $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    $owned = DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tableowner = current_user");

    expect($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse()
        ->and($owned)->toBe(0);
});

it('forces row-level security with the tenant policy on every table with a tenant_id', function (): void {
    createProbeTable();

    $tables = DB::select(<<<'SQL'
        SELECT c.relname AS name, c.relrowsecurity AS enabled, c.relforcerowsecurity AS forced,
               EXISTS (SELECT 1 FROM pg_policies p WHERE p.tablename = c.relname AND p.policyname = ?) AS has_policy
        FROM pg_class c
        JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
        WHERE c.relnamespace = 'public'::regnamespace AND c.relkind = 'r'
        SQL, [TenantIsolation::POLICY]);

    expect($tables)->not->toBeEmpty();
    foreach ($tables as $table) {
        expect([$table->name, $table->enabled, $table->forced, $table->has_policy])
            ->toBe([$table->name, true, true, true]);
    }
});

it('uses BelongsToTenant on every model whose table has a tenant_id', function (): void {
    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        $model = new $class;
        assert($model instanceof Model);

        if (Schema::hasColumn($model->getTable(), 'tenant_id')) {
            expect(class_uses_recursive($model))->toHaveKey(BelongsToTenant::class);
        }
    }
})->throwsNoExceptions();
