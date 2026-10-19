<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->store = Tenant::factory()->create(['slug' => 'bella', 'min_shelf_life_days' => 30]);
    asStore($this->store, function (): void {
        $customer = User::factory()->create();
        $order = function (OrderStatus $status, ?Carbon $paidAt, array $items) use ($customer): void {
            static $number = 0;
            $subtotal = array_sum(array_map(fn (array $i): int => $i[1] * $i[2], $items));
            $order = Order::query()->create([
                'number' => ++$number, 'user_id' => $customer->id, 'status' => $status,
                'subtotal_cents' => $subtotal, 'discount_cents' => 0, 'customer_name' => 'Ana',
                'customer_email' => 'ana@x.test', 'shipping_address' => [], 'expires_at' => now()->addHour(), 'paid_at' => $paidAt,
            ]);
            foreach ($items as [$name, $price, $qty]) {
                $variant = ProductVariant::factory()->create();
                OrderItem::query()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'product_name' => $name,
                    'variant_name' => 'x', 'sku' => $variant->sku, 'unit_price_cents' => $price, 'quantity' => $qty]);
            }
        };

        $order(OrderStatus::Paid, now(), [['Batom', 3000, 2]]);
        $order(OrderStatus::Shipped, now()->subDays(3), [['Batom', 3000, 1], ['Sérum', 12000, 1]]);
        $order(OrderStatus::Delivered, now()->subDays(10), [['Perfume', 25000, 1]]);
        $order(OrderStatus::Delivered, now()->subDays(45), [['Antigo', 9999, 9]]);
        $order(OrderStatus::PendingPayment, null, [['Base', 5000, 1]]);
        $order(OrderStatus::Cancelled, null, [['Base', 5000, 1]]);

        $inStock = ProductVariant::factory()->create();
        StockLot::factory()->create(['variant_id' => $inStock->id, 'expires_on' => today()->addDays(20)]);
        StockLot::factory()->create(['variant_id' => $inStock->id, 'expires_on' => today()->addDays(50)]);
        StockLot::factory()->create(['variant_id' => $inStock->id, 'expires_on' => today()->addYear()]);
    });
    $this->withToken(tokenFor($this->store, UserRole::Staff));
});

it('sums up sales, work waiting and stock needing attention', function (): void {
    $data = $this->getJson($this->storeUrl('bella', 'admin/dashboard'))->assertOk()->json('data');

    expect($data['sales'])->toBe([
        'today_cents' => 6000,
        'last_30_days_cents' => 46000,
        'paid_orders_last_30_days' => 3,
        'average_ticket_cents' => 15333,
    ])
        ->and($data['orders'])->toBe(['awaiting_payment' => 1, 'to_ship' => 1, 'in_transit' => 1])
        ->and($data['stock']['lots_out_of_sale'])->toBe(1)
        ->and($data['stock']['lots_leaving_sale_soon'])->toBe(1)
        ->and($data['top_products_last_30_days'])->toBe([
            ['product_name' => 'Batom', 'units' => 3, 'revenue_cents' => 9000],
            ['product_name' => 'Perfume', 'units' => 1, 'revenue_cents' => 25000],
            ['product_name' => 'Sérum', 'units' => 1, 'revenue_cents' => 12000],
        ]);
});

it('counts variants on sale with nothing to sell', function (): void {
    // The 7 variants created for the orders' items (1+2+1+1+1+1) have no
    // stock; the one with sellable lots does not count.
    expect($this->getJson($this->storeUrl('bella', 'admin/dashboard'))->json('data.stock.variants_out_of_stock'))->toBe(7);
});
