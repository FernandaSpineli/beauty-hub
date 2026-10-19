<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Lots expiring within this many days are no longer sold: nobody
        // wants a sunscreen that expires next week.
        DB::statement(<<<'SQL'
            ALTER TABLE tenants
                ADD COLUMN min_shelf_life_days integer NOT NULL DEFAULT 30
                CHECK (min_shelf_life_days BETWEEN 0 AND 365)
            SQL);

        // Stock arrives in lots, each with the manufacturer's lot code and
        // expiry date. Reserved units belong to orders awaiting payment.
        DB::statement(<<<'SQL'
            CREATE TABLE stock_lots (
                id                uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id         uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                variant_id        uuid NOT NULL,
                lot_code          text NOT NULL CHECK (length(lot_code) BETWEEN 1 AND 40),
                expires_on        date NOT NULL,
                quantity_on_hand  integer NOT NULL CHECK (quantity_on_hand >= 0),
                quantity_reserved integer NOT NULL DEFAULT 0
                                  CHECK (quantity_reserved >= 0 AND quantity_reserved <= quantity_on_hand),
                received_at       timestamptz NOT NULL DEFAULT now(),
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, variant_id, lot_code),
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        DB::statement('CREATE INDEX stock_lots_fefo ON stock_lots (tenant_id, variant_id, expires_on, id)');
        DB::statement('CREATE INDEX stock_lots_expiry ON stock_lots (tenant_id, expires_on) WHERE quantity_on_hand > 0');
        TenantIsolation::enable('stock_lots');

        // Append-only ledger of every stock change, for audits and support.
        DB::statement(<<<'SQL'
            CREATE TABLE stock_movements (
                id         uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id  uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                lot_id     uuid NOT NULL,
                kind       text NOT NULL CHECK (kind IN ('received', 'adjusted', 'reserved', 'released', 'sold')),
                on_hand_delta  integer NOT NULL DEFAULT 0,
                reserved_delta integer NOT NULL DEFAULT 0,
                reason     text CHECK (length(reason) <= 200),
                user_id    uuid,
                reference  text CHECK (length(reference) <= 100),
                created_at timestamptz NOT NULL DEFAULT now(),
                CHECK (on_hand_delta <> 0 OR reserved_delta <> 0),
                FOREIGN KEY (tenant_id, lot_id) REFERENCES stock_lots (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        DB::statement('CREATE INDEX stock_movements_lot ON stock_movements (tenant_id, lot_id, created_at)');
        TenantIsolation::enable('stock_movements');

        // A kit (e.g. "Kit Skincare Noite") has no stock of its own: it is
        // available as long as its components are. Written through Eloquent's
        // belongsToMany, which knows nothing of tenants: the column default
        // takes the session's current tenant.
        DB::statement(<<<'SQL'
            CREATE TABLE kit_components (
                tenant_id            uuid NOT NULL DEFAULT current_tenant_id()
                                     REFERENCES tenants (id) ON DELETE CASCADE,
                kit_variant_id       uuid NOT NULL,
                component_variant_id uuid NOT NULL,
                quantity             integer NOT NULL CHECK (quantity BETWEEN 1 AND 20),
                PRIMARY KEY (tenant_id, kit_variant_id, component_variant_id),
                CHECK (kit_variant_id <> component_variant_id),
                FOREIGN KEY (tenant_id, kit_variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id, component_variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        TenantIsolation::enable('kit_components');

        // Sellable units per variant and the expiry date of the next unit to
        // ship. security_invoker makes the view run with the caller's
        // privileges; without it a view bypasses row-level security.
        DB::statement(<<<'SQL'
            CREATE VIEW variant_stock WITH (security_invoker = true) AS
            WITH lot_stock AS (
                SELECT l.variant_id,
                       sum(l.quantity_on_hand - l.quantity_reserved) AS available,
                       min(l.expires_on) FILTER (WHERE l.quantity_on_hand > l.quantity_reserved) AS next_expiry
                FROM stock_lots l
                JOIN tenants t ON t.id = l.tenant_id
                WHERE l.expires_on > current_date + t.min_shelf_life_days
                GROUP BY l.variant_id
            )
            SELECT v.tenant_id, v.id AS variant_id,
                   coalesce(ls.available, 0)::integer AS available,
                   ls.next_expiry
            FROM product_variants v
            LEFT JOIN lot_stock ls ON ls.variant_id = v.id
            WHERE NOT EXISTS (SELECT 1 FROM kit_components k WHERE k.kit_variant_id = v.id)
            UNION ALL
            SELECT k.tenant_id, k.kit_variant_id,
                   min(coalesce(ls.available, 0) / k.quantity)::integer,
                   min(ls.next_expiry)
            FROM kit_components k
            LEFT JOIN lot_stock ls ON ls.variant_id = k.component_variant_id
            GROUP BY k.tenant_id, k.kit_variant_id
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW variant_stock');
        DB::statement('DROP TABLE kit_components');
        DB::statement('DROP TABLE stock_movements');
        DB::statement('DROP TABLE stock_lots');
        DB::statement('ALTER TABLE tenants DROP COLUMN min_shelf_life_days');
    }
};
