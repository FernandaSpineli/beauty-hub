<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Coupon
 */
final class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'kind' => $this->kind->value,
            'value' => $this->value,
            'min_subtotal_cents' => $this->min_subtotal_cents,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'max_redemptions' => $this->max_redemptions,
            'max_per_customer' => $this->max_per_customer,
            'times_redeemed' => $this->times_redeemed,
            'is_active' => $this->is_active,
        ];
    }
}
