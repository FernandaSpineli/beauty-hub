<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Orders\OrderTransitions;
use App\Tenancy\EachStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Expires orders whose payment window has closed, store by store (orders are
 * only visible inside their store's context), releasing their stock.
 */
#[Signature('orders:expire')]
#[Description('Expire unpaid orders past their payment window')]
final class ExpireOrders extends Command
{
    public function handle(EachStore $stores, OrderTransitions $transitions): int
    {
        $expired = 0;

        $stores->run(function () use ($transitions, &$expired): void {
            Order::query()
                ->where('status', OrderStatus::PendingPayment)
                ->where('expires_at', '<=', now())
                ->orderBy('expires_at')
                ->each(function (Order $order) use ($transitions, &$expired): void {
                    $transitions->expire($order);
                    $expired++;
                });
        });

        $this->info("Expired {$expired} order(s).");

        return self::SUCCESS;
    }
}
