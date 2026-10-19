<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\StockLotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Units of a variant from one manufacturer lot, sharing an expiry date.
 *
 * @property string $id
 * @property string $variant_id
 * @property string $lot_code
 * @property Carbon $expires_on
 * @property int $quantity_on_hand
 * @property int $quantity_reserved
 * @property Carbon $received_at
 * @property-read ProductVariant $variant
 */
final class StockLot extends Model
{
    /** @use HasFactory<StockLotFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity_reserved' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'quantity_on_hand' => 'integer',
            'quantity_reserved' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function available(): int
    {
        return $this->quantity_on_hand - $this->quantity_reserved;
    }

    /**
     * Lots that may still be sold: expiring after the store's minimum
     * shelf life. Same rule as the variant_stock view.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSellable(Builder $query): void
    {
        $query->whereRaw(
            'stock_lots.expires_on > current_date + (SELECT min_shelf_life_days FROM tenants WHERE tenants.id = stock_lots.tenant_id)',
        );
    }

    public function isSellable(Tenant $store): bool
    {
        return $this->expires_on->greaterThan(today()->addDays($store->min_shelf_life_days));
    }
}
