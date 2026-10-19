<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'status' => $this->status->value,
            'subtotal_cents' => $this->subtotal_cents,
            'discount_cents' => $this->discount_cents,
            'total_cents' => $this->total_cents,
            'coupon_code' => $this->coupon_code,
            'customer' => ['name' => $this->customer_name, 'email' => $this->customer_email],
            'shipping_address' => $this->shipping_address,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (OrderItem $item): array => [
                'variant_id' => $item->variant_id,
                'sku' => $item->sku,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'unit_price_cents' => $item->unit_price_cents,
                'quantity' => $item->quantity,
                'line_total_cents' => $item->line_total_cents,
            ])->values()),
            'history' => $this->whenLoaded('history', fn () => $this->history->map(fn (OrderStatusChange $c): array => [
                'from' => $c->from_status?->value,
                'to' => $c->to_status->value,
                'note' => $c->note,
                'at' => $c->created_at->toIso8601String(),
            ])->values()),
            'expires_at' => $this->expires_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
