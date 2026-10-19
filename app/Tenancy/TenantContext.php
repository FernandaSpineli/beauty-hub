<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Context;

/**
 * The tenant the current request or job acts for.
 *
 * Setting it also stores the tenant ID in the PostgreSQL session
 * (app.tenant_id), which the row-level security policies read. So the
 * database enforces isolation even for queries that bypass Eloquent.
 */
final class TenantContext
{
    public const string CONNECTION = 'pgsql';

    private ?Tenant $tenant = null;

    public function __construct(private readonly DatabaseManager $db) {}

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        Context::add('tenant_id', $tenant->id);
        $this->applyTo($this->db->connection(self::CONNECTION));
    }

    public function clear(): void
    {
        $this->tenant = null;
        Context::forget('tenant_id');
        $this->applyTo($this->db->connection(self::CONNECTION));
    }

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->id;
    }

    /**
     * @throws NoTenantContext
     */
    public function require(): Tenant
    {
        return $this->tenant ?? throw new NoTenantContext;
    }

    /**
     * Runs a callback as the given tenant and restores the previous one.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->clear() : $this->set($previous);
        }
    }

    /**
     * Copies the tenant into a database session. Called on set/clear and
     * again whenever the connection is (re)established, since a new session
     * starts without the setting.
     */
    public function applyTo(Connection $connection): void
    {
        $connection->select("SELECT set_config('app.tenant_id', ?, false)", [$this->tenant->id ?? '']);
    }
}
