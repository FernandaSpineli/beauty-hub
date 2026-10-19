<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts belong to one store. The same email can sign up at two
        // stores and get two unrelated accounts.
        DB::statement(<<<'SQL'
            CREATE TABLE users (
                id                uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id         uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                name              text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
                email             text NOT NULL CHECK (email = lower(email) AND length(email) <= 254),
                password          text NOT NULL,
                role              text NOT NULL DEFAULT 'customer'
                                  CHECK (role IN ('owner', 'staff', 'customer')),
                email_verified_at timestamptz,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, email)
            )
            SQL);
        TenantIsolation::enable('users');

        // Laravel's password broker knows nothing about tenants: it reads and
        // writes by email. The tenant comes from the session's current tenant
        // (column default) and row-level security keeps lookups in the store.
        DB::statement(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                tenant_id  uuid NOT NULL DEFAULT current_tenant_id()
                           REFERENCES tenants (id) ON DELETE CASCADE,
                email      text NOT NULL,
                token      text NOT NULL,
                created_at timestamptz,
                PRIMARY KEY (tenant_id, email)
            )
            SQL);
        TenantIsolation::enable('password_reset_tokens');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE password_reset_tokens');
        DB::statement('DROP TABLE users');
    }
};
