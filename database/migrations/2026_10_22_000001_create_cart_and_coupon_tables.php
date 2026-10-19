<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Discount codes, per store. Usage counts are kept by order placement
        // under a row lock, so limits hold under concurrent checkouts.
        DB::statement(<<<'SQL'
            CREATE TABLE coupons (
                id                  uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id           uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                code                text NOT NULL CHECK (code ~ '^[A-Z0-9]{3,30}$'),
                kind                text NOT NULL CHECK (kind IN ('percent', 'fixed')),
                value               integer NOT NULL CHECK (value > 0),
                min_subtotal_cents  bigint NOT NULL DEFAULT 0 CHECK (min_subtotal_cents >= 0),
                starts_at           timestamptz,
                ends_at             timestamptz,
                max_redemptions     integer CHECK (max_redemptions > 0),
                max_per_customer    integer NOT NULL DEFAULT 1 CHECK (max_per_customer > 0),
                times_redeemed      integer NOT NULL DEFAULT 0
                                    CHECK (times_redeemed >= 0 AND (max_redemptions IS NULL OR times_redeemed <= max_redemptions)),
                is_active           boolean NOT NULL DEFAULT true,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                CHECK (kind <> 'percent' OR value <= 100),
                CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at),
                UNIQUE (tenant_id, code),
                UNIQUE (tenant_id, id)
            )
            SQL);
        TenantIsolation::enable('coupons');

        // One cart per customer per store, kept in PostgreSQL (it must survive
        // devices and sessions; Redis would add nothing here).
        DB::statement(<<<'SQL'
            CREATE TABLE carts (
                id         uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id  uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                user_id    uuid NOT NULL,
                coupon_id  uuid,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, user_id),
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id, coupon_id) REFERENCES coupons (tenant_id, id) ON DELETE SET NULL (coupon_id)
            )
            SQL);
        TenantIsolation::enable('carts');

        DB::statement(<<<'SQL'
            CREATE TABLE cart_items (
                id         uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id  uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                cart_id    uuid NOT NULL,
                variant_id uuid NOT NULL,
                quantity   integer NOT NULL CHECK (quantity BETWEEN 1 AND 20),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, cart_id, variant_id),
                FOREIGN KEY (tenant_id, cart_id) REFERENCES carts (tenant_id, id) ON DELETE CASCADE,
                FOREIGN KEY (tenant_id, variant_id) REFERENCES product_variants (tenant_id, id) ON DELETE CASCADE
            )
            SQL);
        TenantIsolation::enable('cart_items');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE cart_items');
        DB::statement('DROP TABLE carts');
        DB::statement('DROP TABLE coupons');
    }
};
