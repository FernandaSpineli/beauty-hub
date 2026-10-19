<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrations run as the schema owner. Every table it creates from here on is
 * readable and writable by the application role, which owns nothing and so
 * stays subject to row-level security.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = $this->applicationRole();

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO {$role}");
    }

    public function down(): void
    {
        $role = $this->applicationRole();

        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE USAGE, SELECT ON SEQUENCES FROM {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM {$role}");
    }

    private function applicationRole(): string
    {
        $role = config('database.connections.pgsql.username');

        if (! is_string($role) || preg_match('/\A[a-z_][a-z0-9_]*\z/', $role) !== 1) {
            throw new RuntimeException('database.connections.pgsql.username must be a plain role name.');
        }

        return $role;
    }
};
