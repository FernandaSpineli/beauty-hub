<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StockLot;
use Illuminate\Http\JsonResponse;

/**
 * Every order that received units from a lot, e.g. when a manufacturer or
 * ANVISA recalls it: who to contact and how many units each got.
 */
final class LotRecall
{
    public function __invoke(StockLot $lot): JsonResponse
    {
        $orders = Order::query()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->join('order_allocations', 'order_allocations.order_item_id', '=', 'order_items.id')
            ->where('order_allocations.lot_id', $lot->id)
            ->whereIn('orders.status', [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered])
            ->groupBy('orders.id')
            ->orderBy('orders.number')
            ->selectRaw('orders.*, sum(order_allocations.quantity) AS units_from_lot')
            ->get();

        return new JsonResponse(['data' => [
            'lot' => ['id' => $lot->id, 'lot_code' => $lot->lot_code, 'expires_on' => $lot->expires_on->toDateString()],
            'orders' => $orders->map(fn (Order $o): array => [
                'id' => $o->id,
                'reference' => $o->reference(),
                'status' => $o->status->value,
                'customer' => ['name' => $o->customer_name, 'email' => $o->customer_email],
                'units_from_lot' => is_numeric($units = $o->getAttribute('units_from_lot')) ? (int) $units : 0,
                'paid_at' => $o->paid_at?->toIso8601String(),
            ])->values(),
        ]]);
    }
}
