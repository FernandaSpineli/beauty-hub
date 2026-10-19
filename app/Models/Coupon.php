<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\Audited;
use App\Enums\CouponKind;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $code
 * @property CouponKind $kind
 * @property int $value
 * @property int $min_subtotal_cents
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $max_redemptions
 * @property int $max_per_customer
 * @property int $times_redeemed
 * @property bool $is_active
 */
#[Fillable(['code', 'kind', 'value', 'min_subtotal_cents', 'starts_at', 'ends_at', 'max_redemptions', 'max_per_customer', 'is_active'])]
final class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use Audited, BelongsToTenant, HasFactory, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'min_subtotal_cents' => 0,
        'max_per_customer' => 1,
        'times_redeemed' => 0,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CouponKind::class,
            'value' => 'integer',
            'min_subtotal_cents' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_redemptions' => 'integer',
            'max_per_customer' => 'integer',
            'times_redeemed' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Discount in centavos for a subtotal; never more than the subtotal.
     */
    public function discountFor(int $subtotalCents): int
    {
        $discount = match ($this->kind) {
            CouponKind::Percent => intdiv($subtotalCents * $this->value, 100),
            CouponKind::Fixed => $this->value,
        };

        return min($discount, $subtotalCents);
    }
}
