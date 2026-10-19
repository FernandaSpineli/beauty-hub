<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StockLot;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockLot
 */
final class StockLotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lot_code' => $this->lot_code,
            'expires_on' => $this->expires_on->toDateString(),
            'on_hand' => $this->quantity_on_hand,
            'reserved' => $this->quantity_reserved,
            'available' => $this->available(),
            'sellable' => $this->isSellable(app(TenantContext::class)->require()),
            'variant' => $this->whenLoaded('variant', fn (): array => [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'name' => $this->variant->name,
                'product' => $this->variant->relationLoaded('product') ? $this->variant->product->name : null,
            ]),
        ];
    }
}
