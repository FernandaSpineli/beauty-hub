<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cart;

use App\Cart\CartQuote;
use App\Cart\CouponRules;
use App\Enums\ProductStatus;
use App\Exceptions\ApiException;
use App\Inventory\InsufficientStock;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The signed-in customer's cart at the current store. Every response is the
 * freshly priced cart.
 */
final class CartController
{
    public function show(Request $request): JsonResponse
    {
        return $this->quote($this->cart($request));
    }

    public function addItem(Request $request): JsonResponse
    {
        /** @var array{variant_id: string, quantity?: int} $data */
        $data = $request->validate([
            'variant_id' => ['required', 'uuid', Rule::exists('product_variants', 'id')],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.CartItem::MAX_QUANTITY],
        ]);
        $cart = $this->cart($request);
        $variant = $this->purchasable($data['variant_id']);

        DB::transaction(function () use ($cart, $variant, $data): void {
            // Serialises changes to this cart (e.g. a double-clicked "add").
            Cart::query()->whereKey($cart->id)->lockForUpdate()->first();
            $item = CartItem::query()->firstOrNew(['cart_id' => $cart->id, 'variant_id' => $variant->id]);
            $item->quantity = min(CartItem::MAX_QUANTITY, ($item->exists ? $item->quantity : 0) + (int) ($data['quantity'] ?? 1));
            $this->assertInStock($variant, $item->quantity);
            $item->save();
        });

        return $this->quote($cart);
    }

    public function updateItem(Request $request, string $variantId): JsonResponse
    {
        /** @var array{quantity: int} $data */
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.CartItem::MAX_QUANTITY]]);
        $cart = $this->cart($request);
        $item = CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->firstOrFail();

        $this->assertInStock($item->variant, (int) $data['quantity']);
        $item->update(['quantity' => (int) $data['quantity']]);

        return $this->quote($cart);
    }

    public function removeItem(Request $request, string $variantId): JsonResponse
    {
        $cart = $this->cart($request);
        CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variantId)->delete();

        return $this->quote($cart);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cart($request);
        $cart->items()->delete();
        $cart->update(['coupon_id' => null]);

        return $this->quote($cart);
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        /** @var array{code: string} $data */
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $cart = $this->cart($request);
        $coupon = Coupon::query()->where('code', mb_strtoupper(trim($data['code'])))->first()
            ?? throw new ApiException(422, 'COUPON_NOT_FOUND', 'This coupon does not exist.');

        $problem = CouponRules::problem($coupon, CartQuote::for($cart)->subtotalCents)
            ?? CouponRules::usedUp($coupon, $this->customer($request));
        if ($problem !== null) {
            throw $problem;
        }

        $cart->update(['coupon_id' => $coupon->id]);

        return $this->quote($cart->fresh() ?? $cart);
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        $cart = $this->cart($request);
        $cart->update(['coupon_id' => null]);

        return $this->quote($cart);
    }

    private function cart(Request $request): Cart
    {
        return Cart::for($this->customer($request));
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    private function purchasable(string $variantId): ProductVariant
    {
        $variant = ProductVariant::query()->with(['product', 'stock'])->findOrFail($variantId);

        if (! $variant->is_active || $variant->product->status !== ProductStatus::Active) {
            throw new ApiException(422, 'VARIANT_UNAVAILABLE', 'This item is not for sale.');
        }

        return $variant;
    }

    private function assertInStock(ProductVariant $variant, int $quantity): void
    {
        $available = $variant->stock()->value('available');
        $available = is_numeric($available) ? (int) $available : 0;

        if ($quantity > $available) {
            throw InsufficientStock::for([['variant_id' => $variant->id, 'requested' => $quantity, 'available' => $available]]);
        }
    }

    private function quote(Cart $cart): JsonResponse
    {
        return new JsonResponse(['data' => CartQuote::for($cart->fresh() ?? $cart)->toArray()]);
    }
}
