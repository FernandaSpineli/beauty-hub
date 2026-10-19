<?php

declare(strict_types=1);

namespace App\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Inventory\Allocation;
use App\Inventory\StockAllocator;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderStatusChange;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Every status change goes through here: the order row is locked, the move
 * is checked against the state machine, and its side effects (stock,
 * coupon usage, history) happen in the same transaction. A cancellation and
 * a payment arriving together can therefore never both apply.
 */
final readonly class OrderTransitions
{
    public function __construct(private StockAllocator $allocator) {}

    public function cancel(Order $order, ?User $by, ?string $note = null): Order
    {
        return $this->move($order, OrderStatus::Cancelled, $by, $note, fn (Order $o) => $this->releaseHolds($o));
    }

    public function expire(Order $order): Order
    {
        return $this->move($order, OrderStatus::Expired, null, 'Not paid in time.', fn (Order $o) => $this->releaseHolds($o));
    }

    public function markPaid(Order $order, ?string $note = null): Order
    {
        return $this->move($order, OrderStatus::Paid, null, $note, function (Order $o): void {
            $this->allocator->sell($this->allocations($o), "order:{$o->id}");
            $o->paid_at = now();
        });
    }

    public function ship(Order $order, User $by, ?string $note = null): Order
    {
        return $this->move($order, OrderStatus::Shipped, $by, $note);
    }

    public function deliver(Order $order, User $by, ?string $note = null): Order
    {
        return $this->move($order, OrderStatus::Delivered, $by, $note);
    }

    /**
     * @param  (Closure(Order): void)|null  $effects
     */
    private function move(Order $order, OrderStatus $to, ?User $by, ?string $note, ?Closure $effects = null): Order
    {
        return DB::transaction(function () use ($order, $to, $by, $note, $effects): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if (! $from->canBecome($to)) {
                throw new ApiException(409, 'ORDER_STATUS_CONFLICT', "The order is {$from->value} and cannot become {$to->value}.", [
                    ['status' => $from->value],
                ]);
            }

            if ($effects !== null) {
                $effects($order);
            }

            $order->status = $to;
            if (in_array($to, [OrderStatus::Cancelled, OrderStatus::Expired, OrderStatus::Delivered], true)) {
                $order->closed_at = now();
            }
            $order->save();

            OrderStatusChange::query()->create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $by?->id,
                'note' => $note,
            ]);

            return $order;
        });
    }

    /**
     * An order that never got paid gives back its stock and coupon use.
     */
    private function releaseHolds(Order $order): void
    {
        $this->allocator->release($this->allocations($order), "order:{$order->id}");

        if ($order->coupon_id !== null) {
            Coupon::query()->lockForUpdate()->findOrFail($order->coupon_id)->decrement('times_redeemed');
            CouponRedemption::query()->where('order_id', $order->id)->delete();
        }
    }

    /**
     * @return array<int, Allocation>
     */
    private function allocations(Order $order): array
    {
        return $order->allocations()->with('item')->get()
            ->map(fn (OrderAllocation $a): Allocation => $a->toAllocation($a->item->variant_id))
            ->values()
            ->all();
    }
}
