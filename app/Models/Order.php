<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $number
 * @property string $user_id
 * @property OrderStatus $status
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $total_cents
 * @property string|null $coupon_id
 * @property string|null $coupon_code
 * @property string $customer_name
 * @property string $customer_email
 * @property array<string, string> $shipping_address
 * @property string|null $idempotency_key
 * @property string|null $request_hash
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderAllocation> $allocations
 * @property-read Collection<int, OrderStatusChange> $history
 * @property-read User $user
 */
final class Order extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['tenant_id', 'total_cents'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'number' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'shipping_address' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('product_name')->orderBy('variant_name');
    }

    /**
     * @return HasManyThrough<OrderAllocation, OrderItem, $this>
     */
    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(OrderAllocation::class, OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusChange, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "BH-000042": what shoppers and support staff read out. */
    public function reference(): string
    {
        return sprintf('BH-%06d', $this->number);
    }
}
