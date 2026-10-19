<?php

declare(strict_types=1);

namespace App\Casts;

use App\Enums\SkinType;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Maps a PostgreSQL text[] of skin types ('{dry,sensitive}') to a collection
 * of enums. The values are plain words, so the array literal needs no quoting.
 *
 * @implements CastsAttributes<Collection<int, SkinType>, iterable<SkinType|string>>
 */
final class SkinTypes implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, SkinType>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): Collection
    {
        if (! is_string($value) || $value === '{}' || $value === '') {
            return new Collection;
        }

        return (new Collection(explode(',', trim($value, '{}'))))
            ->map(fn (string $type): SkinType => SkinType::from($type))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_iterable($value)) {
            throw new InvalidArgumentException('Skin types must be a list.');
        }

        $types = [];
        foreach ($value as $type) {
            $types[] = ($type instanceof SkinType ? $type : SkinType::from((string) $type))->value;
        }

        return '{'.implode(',', array_unique($types)).'}';
    }
}
