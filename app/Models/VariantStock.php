<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Read model over the variant_stock view: sellable units of a variant (or
 * kit) and the expiry date of the next unit to ship.
 *
 * @property string $variant_id
 * @property int $available
 * @property Carbon|null $next_expiry
 */
final class VariantStock extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'variant_stock';

    protected $primaryKey = 'variant_id';

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'available' => 'integer',
            'next_expiry' => 'date',
        ];
    }
}
