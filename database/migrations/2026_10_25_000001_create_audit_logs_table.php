<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // What the store's team changed, by whom, from what to what.
        DB::statement(<<<'SQL'
            CREATE TABLE audit_logs (
                id           uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id    uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id      uuid NOT NULL,
                action       text NOT NULL CHECK (action IN ('created', 'updated', 'deleted')),
                subject_type text NOT NULL,
                subject_id   text NOT NULL,
                changes      jsonb NOT NULL DEFAULT '{}',
                request_id   text,
                created_at   timestamptz NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('CREATE INDEX audit_logs_recent ON audit_logs (tenant_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_logs_subject ON audit_logs (tenant_id, subject_type, subject_id)');
        TenantIsolation::enable('audit_logs');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE audit_logs');
    }
};
