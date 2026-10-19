<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderTransitions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class OrderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['sometimes', Rule::enum(OrderStatus::class)]]);

        return OrderResource::collection(
            Order::query()
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest()
                ->paginate(25),
        );
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(['items', 'history']));
    }

    /**
     * Moves an order along: shipped, delivered, or cancelled while unpaid.
     * "paid" only comes from the payment provider.
     */
    public function updateStatus(Request $request, Order $order, OrderTransitions $transitions): OrderResource
    {
        /** @var array{status: string, note?: string} $data */
        $data = $request->validate([
            'status' => ['required', Rule::in(['shipped', 'delivered', 'cancelled'])],
            'note' => ['sometimes', 'string', 'max:300'],
        ]);
        $by = $request->user();
        assert($by instanceof User);
        $note = $data['note'] ?? null;

        $order = match (OrderStatus::from($data['status'])) {
            OrderStatus::Shipped => $transitions->ship($order, $by, $note),
            OrderStatus::Delivered => $transitions->deliver($order, $by, $note),
            OrderStatus::Cancelled => $transitions->cancel($order, $by, $note),
            default => throw new ApiException(422, 'VALIDATION_FAILED', 'Unsupported status.'),
        };

        return new OrderResource($order->load(['items', 'history']));
    }
}
