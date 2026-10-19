<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $order_id
 * @property string $variant_id
 * @property string $product_name
 * @property string $variant_name
 * @property string $sku
 * @property int $unit_price_cents
 * @property int $quantity
 * @property int $line_total_cents
 */
final class OrderItem extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = ['tenant_id', 'line_total_cents'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'quantity' => 'integer',
            'line_total_cents' => 'integer',
        ];
    }

    /**
     * @return HasMany<OrderAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(OrderAllocation::class);
    }
}
