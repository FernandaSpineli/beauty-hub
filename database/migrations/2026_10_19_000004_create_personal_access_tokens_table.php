<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Sanctum API tokens. Scoped to the store that issued them: a token
        // presented at another store's subdomain is simply not found.
        DB::statement(<<<'SQL'
            CREATE TABLE personal_access_tokens (
                id             uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id      uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                tokenable_type text NOT NULL,
                tokenable_id   uuid NOT NULL,
                name           text NOT NULL,
                token          char(64) NOT NULL UNIQUE,
                abilities      text,
                last_used_at   timestamptz,
                expires_at     timestamptz,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('CREATE INDEX personal_access_tokens_tokenable ON personal_access_tokens (tokenable_type, tokenable_id)');
        DB::statement('CREATE INDEX personal_access_tokens_expires_at ON personal_access_tokens (expires_at)');
        TenantIsolation::enable('personal_access_tokens');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE personal_access_tokens');
    }
};
