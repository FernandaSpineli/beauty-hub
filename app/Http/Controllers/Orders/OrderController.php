<?php

declare(strict_types=1);

namespace App\Http\Controllers\Orders;

use App\Exceptions\ApiException;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Orders\PlaceOrder;
use App\Payments\StartCheckout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The signed-in customer's own orders. Someone else's order is "not found".
 */
final class OrderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return OrderResource::collection(
            Order::query()->where('user_id', $this->customer($request)->id)->latest()->paginate(20),
        );
    }

    public function show(Request $request, string $order): OrderResource
    {
        return new OrderResource($this->own($request, $order)->load(['items', 'history']));
    }

    /**
     * Place an order from the cart.
     *
     * Reserves stock (first-expiring lot first) and holds it until the
     * order is paid or expires. Send an `Idempotency-Key` header (8-100
     * characters): retrying with the same key returns the order placed the
     * first time, with status 200 instead of 201.
     */
    public function store(PlaceOrderRequest $request, PlaceOrder $placeOrder): JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if ($key !== null && (strlen($key) < 8 || strlen($key) > 100)) {
            throw new ApiException(400, 'INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key must be 8 to 100 characters.');
        }

        $address = $request->address();
        [$order, $created] = $placeOrder->handle($this->customer($request), $address, $key, hash('sha256', (string) json_encode($address)));

        /**
         * @status 201
         *
         * @body OrderResource
         */
        return (new OrderResource($order->load(['items', 'history'])))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /**
     * Opens a Stripe Checkout page for an order awaiting payment.
     */
    public function checkout(Request $request, string $order, StartCheckout $checkout): JsonResponse
    {
        $payment = $checkout->handle($this->own($request, $order));

        return new JsonResponse(['data' => [
            'checkout_url' => $payment->checkout_url,
            'expires_at' => $payment->expires_at->toIso8601String(),
        ]]);
    }

    public function cancel(Request $request, string $order, OrderTransitions $transitions): OrderResource
    {
        $customer = $this->customer($request);
        $order = $transitions->cancel($this->own($request, $order), $customer, 'Cancelled by the customer.');

        return new OrderResource($order->load(['items', 'history']));
    }

    private function own(Request $request, string $id): Order
    {
        return Order::query()->where('user_id', $this->customer($request)->id)->findOrFail($id);
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
