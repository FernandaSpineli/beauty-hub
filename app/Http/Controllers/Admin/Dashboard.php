<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Notifications\ExpiringStockNotification;
use App\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The numbers a store's team checks first thing: sales, work waiting, and
 * stock that needs attention. "Today" is in the store's time zone.
 */
final readonly class Dashboard
{
    private const array SOLD = [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered];

    public function __construct(private TenantContext $context) {}

    public function __invoke(): JsonResponse
    {
        $since = now()->subDays(30);
        $sold = Order::query()->whereIn('status', self::SOLD);
        $monthCount = (clone $sold)->where('paid_at', '>=', $since)->count();
        $monthTotal = (int) (clone $sold)->where('paid_at', '>=', $since)->sum('total_cents');

        return new JsonResponse(['data' => [
            'sales' => [
                'today_cents' => (int) (clone $sold)->where('paid_at', '>=', today())->sum('total_cents'),
                'last_30_days_cents' => $monthTotal,
                'paid_orders_last_30_days' => $monthCount,
                'average_ticket_cents' => $monthCount > 0 ? intdiv($monthTotal, $monthCount) : 0,
            ],
            'orders' => [
                'awaiting_payment' => Order::query()->where('status', OrderStatus::PendingPayment)->count(),
                'to_ship' => Order::query()->where('status', OrderStatus::Paid)->count(),
                'in_transit' => Order::query()->where('status', OrderStatus::Shipped)->count(),
            ],
            'stock' => $this->stock(),
            'top_products_last_30_days' => $this->topProducts($since),
        ]]);
    }

    /**
     * @return array<string, int>
     */
    private function stock(): array
    {
        $cutoff = today()->addDays($this->context->require()->min_shelf_life_days);

        return [
            'variants_out_of_stock' => ProductVariant::query()
                ->where('is_active', true)
                ->whereHas('product', fn ($q) => $q->visible())
                ->whereHas('stock', fn ($q) => $q->where('available', 0))
                ->count(),
            'lots_out_of_sale' => StockLot::query()->where('quantity_on_hand', '>', 0)->where('expires_on', '<=', $cutoff)->count(),
            'lots_leaving_sale_soon' => StockLot::query()->where('quantity_on_hand', '>', 0)
                ->where('expires_on', '>', $cutoff)
                ->where('expires_on', '<=', $cutoff->copy()->addDays(ExpiringStockNotification::WINDOW_DAYS))
                ->count(),
        ];
    }

    /**
     * @return list<array{product_name: string, units: int, revenue_cents: int}>
     */
    private function topProducts(DateTimeInterface $since): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', array_map(fn (OrderStatus $s): string => $s->value, self::SOLD))
            ->where('orders.paid_at', '>=', $since)
            ->groupBy('order_items.product_name')
            ->orderByDesc(DB::raw('sum(order_items.quantity)'))
            ->orderBy('order_items.product_name')
            ->limit(5)
            ->get([
                'order_items.product_name',
                DB::raw('sum(order_items.quantity)::integer AS units'),
                DB::raw('sum(order_items.line_total_cents)::bigint AS revenue_cents'),
            ]);

        $top = [];
        /** @var object{product_name: string, units: int, revenue_cents: int} $row */
        foreach ($rows as $row) {
            $top[] = ['product_name' => $row->product_name, 'units' => $row->units, 'revenue_cents' => $row->revenue_cents];
        }

        return $top;
    }
}
