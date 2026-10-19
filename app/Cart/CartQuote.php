<?php

declare(strict_types=1);

namespace App\Cart;

use App\Enums\ProductStatus;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;

/**
 * A cart priced with current prices and stock. Lines that can no longer be
 * bought are flagged rather than silently dropped, so the shopper sees why.
 */
final readonly class CartQuote
{
    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function __construct(
        public array $lines,
        public int $subtotalCents,
        public int $discountCents,
        public ?string $couponCode,
        public ?ApiException $couponProblem,
        public bool $canCheckout,
    ) {}

    public static function for(Cart $cart): self
    {
        $cart->loadMissing(['items.variant.product', 'items.variant.stock', 'coupon']);

        $lines = [];
        $subtotal = 0;
        $blocked = false;

        foreach ($cart->items as $item) {
            $problem = self::problemWith($item);
            $lineTotal = $item->variant->price_cents * $item->quantity;
            $blocked = $blocked || $problem !== null;
            if ($problem !== 'unavailable') {
                $subtotal += $lineTotal;
            }

            $lines[] = [
                'variant_id' => $item->variant_id,
                'sku' => $item->variant->sku,
                'name' => $item->variant->name,
                'shade_hex' => $item->variant->shade_hex,
                'product' => ['name' => $item->variant->product->name, 'slug' => $item->variant->product->slug],
                'quantity' => $item->quantity,
                'unit_price_cents' => $item->variant->price_cents,
                'line_total_cents' => $lineTotal,
                'problem' => $problem,
            ];
        }

        $coupon = $cart->coupon;
        $couponProblem = $coupon !== null ? CouponRules::problem($coupon, $subtotal) : null;
        $discount = $coupon !== null && $couponProblem === null ? $coupon->discountFor($subtotal) : 0;

        return new self(
            $lines,
            $subtotal,
            $discount,
            $coupon?->code,
            $couponProblem,
            $lines !== [] && ! $blocked,
        );
    }

    public function totalCents(): int
    {
        return $this->subtotalCents - $this->discountCents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => $this->lines,
            'subtotal_cents' => $this->subtotalCents,
            'discount_cents' => $this->discountCents,
            'total_cents' => $this->totalCents(),
            'coupon' => $this->couponCode === null ? null : [
                'code' => $this->couponCode,
                'discount_cents' => $this->discountCents,
                'problem' => $this->couponProblem === null ? null : [
                    'code' => $this->couponProblem->errorCode,
                    'message' => $this->couponProblem->getMessage(),
                ],
            ],
            'can_checkout' => $this->canCheckout,
        ];
    }

    private static function problemWith(CartItem $item): ?string
    {
        $variant = $item->variant;

        if (! $variant->is_active || $variant->product->status !== ProductStatus::Active) {
            return 'unavailable';
        }

        return $item->quantity > ($variant->stock->available ?? 0) ? 'insufficient_stock' : null;
    }
}
