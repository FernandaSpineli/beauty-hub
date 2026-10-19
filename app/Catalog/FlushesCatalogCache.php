<?php

declare(strict_types=1);

namespace App\Catalog;

use Illuminate\Database\Eloquent\Model;

/**
 * For catalog models: any change clears the store's cached catalog.
 *
 * @phpstan-require-extends Model
 */
trait FlushesCatalogCache
{
    public static function bootFlushesCatalogCache(): void
    {
        $flush = fn () => app(CatalogCache::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }
}
