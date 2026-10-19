<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
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

/**
 * Runs a callback as the given store, e.g. to create its data.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function asStore(Tenant $store, callable $callback): mixed
{
    return app(TenantContext::class)->run($store, $callback);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function storeUser(Tenant $store, array $attributes = []): User
{
    return asStore($store, fn () => User::factory()->create($attributes));
}

/**
 * Creates a user with the given role at the store and returns an API token.
 */
function tokenFor(Tenant $store, UserRole $role = UserRole::Owner): string
{
    return asStore($store, fn () => User::factory()->create(['role' => $role])->createToken('test')->plainTextToken);
}
