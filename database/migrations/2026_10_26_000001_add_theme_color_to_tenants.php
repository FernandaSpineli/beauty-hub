<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Each storefront's accent colour.
        DB::statement("ALTER TABLE tenants ADD COLUMN theme_color text NOT NULL DEFAULT '#b0476b' CHECK (theme_color ~ '^#[0-9a-f]{6}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP COLUMN theme_color');
    }
};
