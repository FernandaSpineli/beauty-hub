<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Human-friendly order numbers, sequential per store.
        DB::statement('ALTER TABLE tenants ADD COLUMN last_order_number integer NOT NULL DEFAULT 0');

        DB::statement(<<<'SQL'
            CREATE TABLE orders (
                id               uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id        uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                number           integer NOT NULL CHECK (number > 0),
                user_id          uuid NOT NULL,
                status           text NOT NULL DEFAULT 'pending_payment'
                                 CHECK (status IN ('pending_payment', 'paid', 'shipped', 'delivered', 'cancelled', 'expired')),
                subtotal_cents   bigint NOT NULL CHECK (subtotal_cents > 0),
                discount_cents   bigint NOT NULL DEFAULT 0 CHECK (discount_cents >= 0 AND discount_cents <= subtotal_cents),
                total_cents      bigint GENERATED ALWAYS AS (subtotal_cents - discount_cents) STORED,
                coupon_id        uuid,
                coupon_code      text,
                customer_name    text NOT NULL,
                customer_email   text NOT NULL,
                shipping_address jsonb NOT NULL,
                idempotency_key  text CHECK (length(idempotency_key) BETWEEN 8 AND 100),
                request_hash     text,
                expires_at       timestamptz NOT NULL,
                paid_at          timestamptz,
                closed_at        timestamptz,
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, number),
                UNIQUE (tenant_id, id),
                UNIQUE (tenant_id, user_id, idempotency_key),
                CHECK ((coupon_id IS NULL) = (coupon_code IS NULL)),
                FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, coupon_id) REFERENCES coupons (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        DB::statement('CREATE INDEX orders_user ON orders (tenant_id, user_id, created_at DESC)');
        DB::statement('CREATE INDEX orders_status ON orders (tenant_id, status, created_at DESC)');
        // The expiry sweep looks only at orders still waiting for payment.
        DB::statement("CREATE INDEX orders_pending_expiry ON orders (expires_at) WHERE status = 'pending_payment'");
        TenantIsolation::enable('orders');

        // Lines keep a copy of name, SKU and price: later catalog edits must
        // not rewrite past orders.
        DB::statement(<<<'SQL'
            CREATE TABLE order_items (
                id               uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id        uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                order_id         uuid NOT NULL,
                variant_id       uuid NOT NULL,
                product_name     text NOT NULL,
                variant_name     text NOT NULL,
                sku              text NOT NULL,
                unit_price_cents bigint NOT NULL CHECK (unit_price_cents > 0),
                quantity         integer NOT NULL CHECK (quantity > 0),
                line_total_cents bigint GENERATED ALWAYS AS (unit_price_cents * quantity) STORED,
                UNIQUE (tenant_id, order_id, variant_id),
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id, variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        TenantIsolation::enable('order_items');

        // Which lot each unit came from: releases and sales replay exactly
        // these rows, and a lot recall finds every order that received it.
        DB::statement(<<<'SQL'
            CREATE TABLE order_allocations (
                id            uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id     uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                order_item_id uuid NOT NULL,
                lot_id        uuid NOT NULL,
                variant_id    uuid NOT NULL,
                quantity      integer NOT NULL CHECK (quantity > 0),
                FOREIGN KEY (tenant_id, order_item_id) REFERENCES order_items (tenant_id, id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id, lot_id) REFERENCES stock_lots (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        DB::statement('CREATE INDEX order_allocations_item ON order_allocations (tenant_id, order_item_id)');
        DB::statement('CREATE INDEX order_allocations_lot ON order_allocations (tenant_id, lot_id)');
        TenantIsolation::enable('order_allocations');

        DB::statement(<<<'SQL'
            CREATE TABLE order_status_history (
                id          uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id   uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                order_id    uuid NOT NULL,
                from_status text,
                to_status   text NOT NULL,
                user_id     uuid,
                note        text CHECK (length(note) <= 300),
                created_at  timestamptz NOT NULL DEFAULT now(),
                FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, id) ON DELETE CASCADE
            )
            SQL);
        DB::statement('CREATE INDEX order_status_history_order ON order_status_history (tenant_id, order_id, created_at)');
        TenantIsolation::enable('order_status_history');

        // One row per order that used a coupon; removed again if the order
        // is cancelled or expires, so only real purchases count.
        DB::statement(<<<'SQL'
            CREATE TABLE coupon_redemptions (
                id         uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id  uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                coupon_id  uuid NOT NULL,
                user_id    uuid NOT NULL,
                order_id   uuid NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, order_id),
                FOREIGN KEY (tenant_id, coupon_id) REFERENCES coupons (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, order_id) REFERENCES orders (tenant_id, id) ON DELETE CASCADE
            )
            SQL);
        DB::statement('CREATE INDEX coupon_redemptions_customer ON coupon_redemptions (tenant_id, coupon_id, user_id)');
        TenantIsolation::enable('coupon_redemptions');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE coupon_redemptions');
        DB::statement('DROP TABLE order_status_history');
        DB::statement('DROP TABLE order_allocations');
        DB::statement('DROP TABLE order_items');
        DB::statement('DROP TABLE orders');
        DB::statement('ALTER TABLE tenants DROP COLUMN last_order_number');
    }
};
