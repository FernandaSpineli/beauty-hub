<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Stores on the platform. This table is not tenant-scoped: it is how
        // a request's subdomain is turned into a tenant in the first place.
        DB::statement(<<<'SQL'
            CREATE TABLE tenants (
                id         uuid PRIMARY KEY DEFAULT uuidv7(),
                slug       text NOT NULL UNIQUE
                           CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{1,38}[a-z0-9])$'),
                name       text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                status     text NOT NULL DEFAULT 'active'
                           CHECK (status IN ('active', 'suspended')),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            )
            SQL);

        // The tenant of the current request or job, set per connection by
        // App\Tenancy\TenantContext. NULL when none is set, which makes every
        // row-level security policy match nothing (fail closed). OR REPLACE
        // because migrate:fresh drops tables but leaves functions behind.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION current_tenant_id() RETURNS uuid
            LANGUAGE sql STABLE
            AS $$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION current_tenant_id()');
        DB::statement('DROP TABLE tenants');
    }
};
