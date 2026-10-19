<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExpiringStockNotification;
use App\Tenancy\EachStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('stock:expiry-alerts')]
#[Description('Email each store\'s team about lots out of sale or leaving sale soon')]
final class SendExpiryAlerts extends Command
{
    public function handle(EachStore $stores): int
    {
        $sent = 0;

        $stores->run(function (Tenant $store) use (&$sent): void {
            $cutoff = today()->addDays($store->min_shelf_life_days);
            $lots = StockLot::query()
                ->with('variant.product')
                ->where('quantity_on_hand', '>', 0)
                ->where('expires_on', '<=', $cutoff->copy()->addDays(ExpiringStockNotification::WINDOW_DAYS))
                ->orderBy('expires_on')
                ->get();

            if ($lots->isEmpty()) {
                return;
            }

            $outOfSale = fn (StockLot $lot): bool => $lot->expires_on->lessThanOrEqualTo($cutoff);
            $team = User::query()->whereIn('role', [UserRole::Owner, UserRole::Staff])->get();
            Notification::send($team, new ExpiringStockNotification(
                $lots->filter($outOfSale)->values(),
                $lots->reject($outOfSale)->values(),
            ));
            $sent++;
        });

        $this->info("Alerted {$sent} store(s).");

        return self::SUCCESS;
    }
}
