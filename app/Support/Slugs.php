<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Generates a slug that is free in the current store ("batom-matte",
 * "batom-matte-2", ...). Row-level security scopes the lookup to the store;
 * the unique (tenant_id, slug) index catches any race.
 */
final class Slugs
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function unique(string $model, string $source, ?string $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;

        for ($n = 2; self::taken($model, $slug, $ignoreId); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function taken(string $model, string $slug, ?string $ignoreId): bool
    {
        return $model::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
