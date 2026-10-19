<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Unpaid orders give their stock back as soon as their window closes.
Schedule::command('orders:expire')->everyMinute()->withoutOverlapping()->onOneServer();

// Safety net for Stripe events whose job never ran.
Schedule::command('stripe:retry-events')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Morning note to each store's team about expiring lots.
Schedule::command('stock:expiry-alerts')->dailyAt('07:00')->timezone('America/Sao_Paulo')->onOneServer();

Schedule::command('tokens:prune --hours=24')->dailyAt('03:30')->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
