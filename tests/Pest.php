<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantIsolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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

/**
 * A valid Brazilian shipping address for checkout requests.
 *
 * @return array<string, string>
 */
function address(array $overrides = []): array
{
    return [
        'postal_code' => '01310-100', 'street' => 'Avenida Paulista', 'number' => '1578',
        'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP', ...$overrides,
    ];
}

/**
 * Posts a Stripe event to the webhook, signed the way Stripe signs it.
 *
 * @param  array<string, mixed>  $object
 */
function stripeEvent(string $type, array $object, ?string $id = null, ?string $secret = null): TestResponse
{
    $payload = (string) json_encode([
        'id' => $id ?? 'evt_'.bin2hex(random_bytes(8)),
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => $object],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? config()->string('services.stripe.webhook_secret'));

    return test()->call('POST', 'http://'.config('tenancy.central_domain').'/api/v1/stripe/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], $payload);
}
