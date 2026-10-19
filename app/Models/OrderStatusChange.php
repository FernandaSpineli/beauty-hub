<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property string|null $user_id
 * @property string|null $note
 * @property Carbon $created_at
 */
final class OrderStatusChange extends Model
{
    use BelongsToTenant, HasUuids;

    public const null UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected $guarded = ['tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
        ];
    }
}
