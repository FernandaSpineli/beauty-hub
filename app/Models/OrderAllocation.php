<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventory\Allocation;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $order_item_id
 * @property string $lot_id
 * @property string $variant_id
 * @property int $quantity
 * @property-read OrderItem $item
 */
final class OrderAllocation extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = ['tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function toAllocation(string $forVariantId): Allocation
    {
        return new Allocation($this->lot_id, $this->variant_id, $forVariantId, $this->quantity);
    }
}
