<?php

declare(strict_types=1);

namespace App\Orders;

use App\Cart\CartQuote;
use App\Cart\CouponRules;
use App\Enums\OrderStatus;
use App\Exceptions\ApiException;
use App\Inventory\StockAllocator;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\OrderAllocation;
use App\Models\OrderItem;
use App\Models\OrderStatusChange;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns the customer's cart into an order awaiting payment, in one
 * transaction: stock reserved (first-expiring lot first), coupon usage
 * counted, cart emptied. Retrying with the same Idempotency-Key returns the
 * same order instead of placing a second one.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private StockAllocator $allocator,
        private TenantContext $context,
    ) {}

    /**
     * @param  array<string, string>  $address
     * @return array{0: Order, 1: bool} the order, and whether it was created now
     */
    public function handle(User $customer, array $address, ?string $idempotencyKey, string $requestHash): array
    {
        return DB::transaction(function () use ($customer, $address, $idempotencyKey, $requestHash): array {
            // Locking the cart serialises this customer's checkouts, so a
            // concurrent retry with the same key waits here and then finds
            // the order the first attempt placed.
            $cart = Cart::query()->whereKey(Cart::for($customer)->id)->lockForUpdate()->firstOrFail();

            $existing = $this->previous($customer, $idempotencyKey, $requestHash);
            if ($existing !== null) {
                return [$existing, false];
            }

            $quote = CartQuote::for($cart);
            $this->assertReady($quote);
            $coupon = $this->lockCoupon($cart, $customer, $quote->subtotalCents);

            $orderId = (string) Str::uuid7();
            $quantities = $cart->items->mapWithKeys(fn (CartItem $i): array => [$i->variant_id => $i->quantity])->all();
            $allocations = $this->allocator->reserve($quantities, "order:{$orderId}");

            $order = Order::query()->create([
                'id' => $orderId,
                'number' => $this->nextNumber(),
                'user_id' => $customer->id,
                'status' => OrderStatus::PendingPayment,
                'subtotal_cents' => $quote->subtotalCents,
                'discount_cents' => $quote->discountCents,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'shipping_address' => $address,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'expires_at' => now()->addMinutes(config()->integer('orders.reservation_minutes')),
            ]);

            foreach ($cart->items as $line) {
                $item = OrderItem::query()->create([
                    'order_id' => $order->id,
                    'variant_id' => $line->variant_id,
                    'product_name' => $line->variant->product->name,
                    'variant_name' => $line->variant->name,
                    'sku' => $line->variant->sku,
                    'unit_price_cents' => $line->variant->price_cents,
                    'quantity' => $line->quantity,
                ]);

                foreach ($allocations as $allocation) {
                    if ($allocation->forVariantId === $line->variant_id) {
                        OrderAllocation::query()->create([
                            'order_item_id' => $item->id,
                            'lot_id' => $allocation->lotId,
                            'variant_id' => $allocation->variantId,
                            'quantity' => $allocation->quantity,
                        ]);
                    }
                }
            }

            OrderStatusChange::query()->create([
                'order_id' => $order->id,
                'to_status' => OrderStatus::PendingPayment,
                'user_id' => $customer->id,
            ]);

            if ($coupon !== null) {
                CouponRedemption::query()->create(['coupon_id' => $coupon->id, 'user_id' => $customer->id, 'order_id' => $order->id]);
                $coupon->increment('times_redeemed');
            }

            $cart->items()->delete();
            $cart->update(['coupon_id' => null]);

            // Reload for the columns PostgreSQL computes (total_cents).
            return [$order->refresh(), true];
        });
    }

    private function previous(User $customer, ?string $key, string $requestHash): ?Order
    {
        if ($key === null) {
            return null;
        }

        $order = Order::query()->where('user_id', $customer->id)->where('idempotency_key', $key)->first();

        if ($order !== null && $order->request_hash !== $requestHash) {
            throw new ApiException(422, 'IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used for a different request.');
        }

        return $order;
    }

    private function assertReady(CartQuote $quote): void
    {
        if ($quote->lines === []) {
            throw new ApiException(422, 'CART_EMPTY', 'The cart is empty.');
        }

        if (! $quote->canCheckout) {
            $problems = array_values(array_filter($quote->lines, fn (array $line): bool => $line['problem'] !== null));

            throw new ApiException(409, 'CART_NOT_READY', 'Some items can no longer be bought as they are.', array_map(
                fn (array $line): array => ['variant_id' => $line['variant_id'], 'problem' => $line['problem']],
                $problems,
            ));
        }
    }

    /**
     * Locks the coupon row, so its usage limits hold under concurrent
     * checkouts, and checks it once more.
     */
    private function lockCoupon(Cart $cart, User $customer, int $subtotal): ?Coupon
    {
        if ($cart->coupon_id === null) {
            return null;
        }

        $coupon = Coupon::query()->lockForUpdate()->findOrFail($cart->coupon_id);
        $problem = CouponRules::problem($coupon, $subtotal) ?? CouponRules::usedUp($coupon, $customer);
        if ($problem !== null) {
            throw $problem;
        }

        return $coupon;
    }

    private function nextNumber(): int
    {
        $number = DB::scalar(
            'UPDATE tenants SET last_order_number = last_order_number + 1 WHERE id = ? RETURNING last_order_number',
            [$this->context->require()->id],
        );
        assert(is_int($number));

        return $number;
    }
}
