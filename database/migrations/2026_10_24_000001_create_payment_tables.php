<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Each store is paid through its own Stripe Connect account.
        DB::statement(<<<'SQL'
            ALTER TABLE tenants
                ADD COLUMN stripe_account_id text UNIQUE CHECK (stripe_account_id ~ '^acct_[A-Za-z0-9]+$'),
                ADD COLUMN stripe_charges_enabled boolean NOT NULL DEFAULT false
            SQL);

        // One row per Stripe Checkout session opened for an order.
        DB::statement(<<<'SQL'
            CREATE TABLE payments (
                id                    uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id             uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                order_id              uuid NOT NULL,
                checkout_session_id   text NOT NULL UNIQUE,
                payment_intent_id     text,
                checkout_url          text NOT NULL,
                status                text NOT NULL DEFAULT 'open'
                                      CHECK (status IN ('open', 'processing', 'succeeded', 'failed', 'expired', 'needs_refund')),
                amount_cents          bigint NOT NULL CHECK (amount_cents > 0),
                application_fee_cents bigint NOT NULL CHECK (application_fee_cents >= 0),
                expires_at            timestamptz NOT NULL,
                note                  text,
                created_at            timestamptz NOT NULL DEFAULT now(),
                updated_at            timestamptz NOT NULL DEFAULT now(),
                FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        DB::statement('CREATE INDEX payments_order ON payments (tenant_id, order_id, created_at)');
        TenantIsolation::enable('payments');

        // Every Stripe event received, verified and stored before any work,
        // keyed by Stripe's event ID so redeliveries are recognised. Not
        // tenant-scoped: an event names its store only once it is parsed.
        DB::statement(<<<'SQL'
            CREATE TABLE stripe_events (
                id           text PRIMARY KEY,
                type         text NOT NULL,
                payload      jsonb NOT NULL,
                received_at  timestamptz NOT NULL DEFAULT now(),
                processed_at timestamptz,
                outcome      text
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE stripe_events');
        DB::statement('DROP TABLE payments');
        DB::statement('ALTER TABLE tenants DROP COLUMN stripe_charges_enabled, DROP COLUMN stripe_account_id');
    }
};
