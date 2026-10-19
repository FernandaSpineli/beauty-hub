<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry of the append-only stock ledger.
 *
 * @property string $id
 * @property string $lot_id
 * @property StockMovementKind $kind
 * @property int $on_hand_delta
 * @property int $reserved_delta
 * @property string|null $reason
 * @property string|null $user_id
 * @property string|null $reference
 * @property Carbon $created_at
 */
final class StockMovement extends Model
{
    use BelongsToTenant, HasUuids;

    public const null UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => StockMovementKind::class,
            'on_hand_delta' => 'integer',
            'reserved_delta' => 'integer',
        ];
    }
}
