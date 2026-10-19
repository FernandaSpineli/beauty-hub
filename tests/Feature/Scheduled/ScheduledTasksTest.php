<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ProcessStripeEvent;
use App\Models\PersonalAccessToken;
use App\Models\StockLot;
use App\Models\StripeEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExpiringStockNotification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('schedules the recurring work', function (): void {
    $expressionOf = fn (string $command): ?string => collect(app(Schedule::class)->events())
        ->first(fn (Event $e): bool => str_ends_with((string) $e->command, $command))?->expression;

    expect($expressionOf('orders:expire'))->toBe('* * * * *')
        ->and($expressionOf('stripe:retry-events'))->toBe('*/5 * * * *')
        ->and($expressionOf('stock:expiry-alerts'))->toBe('0 7 * * *')
        ->and($expressionOf('tokens:prune --hours=24'))->toBe('30 3 * * *');
});

it('alerts each store\'s team about lots out of sale or leaving sale soon', function (): void {
    Notification::fake();
    $store = Tenant::factory()->create(['min_shelf_life_days' => 30]);
    [$owner, $staff, $customer] = asStore($store, function (): array {
        StockLot::factory()->create(['lot_code' => 'OUT', 'expires_on' => today()->addDays(20)]);
        StockLot::factory()->create(['lot_code' => 'SOON', 'expires_on' => today()->addDays(45)]);
        StockLot::factory()->create(['lot_code' => 'FINE', 'expires_on' => today()->addYear()]);
        StockLot::factory()->create(['lot_code' => 'EMPTY', 'expires_on' => today()->addDays(5), 'quantity_on_hand' => 0]);

        return [User::factory()->owner()->create(), User::factory()->staff()->create(), User::factory()->create()];
    });
    // A store with nothing expiring hears nothing.
    $calm = Tenant::factory()->create();
    $calmOwner = storeUser($calm, ['role' => UserRole::Owner]);
    asStore($calm, fn () => StockLot::factory()->create(['expires_on' => today()->addYear()]));

    Artisan::call('stock:expiry-alerts');

    Notification::assertSentTo([$owner, $staff], ExpiringStockNotification::class, fn (ExpiringStockNotification $n): bool => $n->outOfSale->pluck('lot_code')->all() === ['OUT']
        && $n->leavingSoon->pluck('lot_code')->all() === ['SOON']);
    Notification::assertNotSentTo([$customer, $calmOwner], ExpiringStockNotification::class);
});

it('prunes expired tokens in every store, which Sanctum\'s own command cannot', function (): void {
    $stores = Tenant::factory()->count(2)->create();
    foreach ($stores as $store) {
        asStore($store, function (): void {
            $user = User::factory()->create();
            $user->createToken('old', ['*'], now()->subDays(3));
            $user->createToken('current', ['*'], now()->addDay());
        });
    }

    // Outside any store, row-level security hides every token.
    Artisan::call('sanctum:prune-expired', ['--hours' => 24]);
    expect(asStore($stores[0], fn () => PersonalAccessToken::query()->count()))->toBe(2);

    Artisan::call('tokens:prune', ['--hours' => 24]);

    foreach ($stores as $store) {
        expect(asStore($store, fn () => PersonalAccessToken::query()->pluck('name')->all()))->toBe(['current']);
    }
});

it('re-queues Stripe events whose processing never ran', function (): void {
    Queue::fake();
    DB::table('stripe_events')->insert([
        ['id' => 'evt_stuck', 'type' => 'x', 'payload' => '{}', 'received_at' => now()->subMinutes(10), 'processed_at' => null],
        ['id' => 'evt_fresh', 'type' => 'x', 'payload' => '{}', 'received_at' => now()->subMinute(), 'processed_at' => null],
        ['id' => 'evt_done', 'type' => 'x', 'payload' => '{}', 'received_at' => now()->subMinutes(10), 'processed_at' => now()],
    ]);

    Artisan::call('stripe:retry-events');

    Queue::assertPushed(ProcessStripeEvent::class, 1);
    Queue::assertPushed(ProcessStripeEvent::class, fn (ProcessStripeEvent $job): bool => $job->eventId === 'evt_stuck');
    expect(StripeEvent::query()->count())->toBe(3);
});
