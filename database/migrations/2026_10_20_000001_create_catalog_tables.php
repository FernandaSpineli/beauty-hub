<?php

declare(strict_types=1);

use App\Tenancy\TenantIsolation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Every reference between catalog tables is a composite foreign key that
 * includes tenant_id. PostgreSQL checks foreign keys without row-level
 * security, so a plain brand_id could point at another store's brand; with
 * (tenant_id, brand_id) the database itself keeps references in the store.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Both are trusted extensions: the database owner may create them.
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // unaccent() is only STABLE (its dictionary could change), so it
        // cannot feed a generated column; pinning the dictionary makes this
        // wrapper safe to declare IMMUTABLE.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION search_normalize(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT lower(public.unaccent('public.unaccent'::regdictionary, $1)) $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE brands (
                id          uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id   uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                name        text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
                slug        text NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                search_text text GENERATED ALWAYS AS (search_normalize(name)) STORED,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, slug),
                UNIQUE (tenant_id, id)
            )
            SQL);
        DB::statement('CREATE INDEX brands_search ON brands USING gin (search_text gin_trgm_ops)');
        TenantIsolation::enable('brands');

        // Two levels at most (e.g. Maquiagem > Batons), enforced in the app.
        DB::statement(<<<'SQL'
            CREATE TABLE categories (
                id         uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id  uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                parent_id  uuid,
                name       text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
                slug       text NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                position   integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, slug),
                UNIQUE (tenant_id, id),
                CHECK (parent_id <> id),
                FOREIGN KEY (tenant_id, parent_id) REFERENCES categories (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        TenantIsolation::enable('categories');

        DB::statement(<<<'SQL'
            CREATE TABLE products (
                id                  uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id           uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                brand_id            uuid NOT NULL,
                category_id         uuid NOT NULL,
                name                text NOT NULL CHECK (length(name) BETWEEN 1 AND 160),
                slug                text NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                description         text NOT NULL DEFAULT '',
                ingredients         text NOT NULL DEFAULT '',
                skin_types          text[] NOT NULL DEFAULT '{}'
                                    CHECK (skin_types <@ ARRAY['normal', 'dry', 'oily', 'combination', 'sensitive']),
                is_vegan            boolean NOT NULL DEFAULT false,
                is_cruelty_free     boolean NOT NULL DEFAULT false,
                anvisa_registration text CHECK (anvisa_registration ~ '^[0-9.]{5,20}$'),
                image_url           text CHECK (image_url ~ '^https?://'),
                status              text NOT NULL DEFAULT 'draft'
                                    CHECK (status IN ('draft', 'active', 'archived')),
                search_text         text GENERATED ALWAYS AS (search_normalize(name)) STORED,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, slug),
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, brand_id) REFERENCES brands (tenant_id, id) ON DELETE RESTRICT,
                FOREIGN KEY (tenant_id, category_id) REFERENCES categories (tenant_id, id) ON DELETE RESTRICT
            )
            SQL);
        DB::statement('CREATE INDEX products_search ON products USING gin (search_text gin_trgm_ops)');
        DB::statement('CREATE INDEX products_listing ON products (tenant_id, status, category_id)');
        DB::statement('CREATE INDEX products_brand ON products (tenant_id, brand_id)');
        TenantIsolation::enable('products');

        // What is actually sold: a shade (Nude 02, with its swatch colour) or
        // a size (50 ml). Prices in centavos.
        DB::statement(<<<'SQL'
            CREATE TABLE product_variants (
                id                     uuid PRIMARY KEY DEFAULT uuidv7(),
                tenant_id              uuid NOT NULL REFERENCES tenants (id) ON DELETE CASCADE,
                product_id             uuid NOT NULL,
                sku                    text NOT NULL CHECK (sku ~ '^[A-Z0-9]+(-[A-Z0-9]+)*$' AND length(sku) <= 40),
                name                   text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
                shade_hex              text CHECK (shade_hex ~ '^#[0-9a-f]{6}$'),
                price_cents            bigint NOT NULL CHECK (price_cents > 0),
                compare_at_price_cents bigint CHECK (compare_at_price_cents > price_cents),
                is_active              boolean NOT NULL DEFAULT true,
                position               integer NOT NULL DEFAULT 0,
                created_at             timestamptz NOT NULL DEFAULT now(),
                updated_at             timestamptz NOT NULL DEFAULT now(),
                UNIQUE (tenant_id, sku),
                UNIQUE (tenant_id, id),
                FOREIGN KEY (tenant_id, product_id) REFERENCES products (tenant_id, id) ON DELETE CASCADE
            )
            SQL);
        DB::statement('CREATE INDEX product_variants_product ON product_variants (tenant_id, product_id)');
        TenantIsolation::enable('product_variants');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE product_variants');
        DB::statement('DROP TABLE products');
        DB::statement('DROP TABLE categories');
        DB::statement('DROP TABLE brands');
        DB::statement('DROP FUNCTION search_normalize(text)');
    }
};
