<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Real concurrency: each checkout runs in its own PHP process with its own
 * database connection, all starting at the same instant. Data is committed
 * (no wrapping transaction), so it is removed afterwards.
 */

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'owner', '--force' => true]);
    $this->store = Tenant::factory()->create();
    $this->context = app(TenantContext::class);
    $this->context->set($this->store);
});

afterEach(function (): void {
    $this->context->clear();
    DB::connection('owner')->statement('TRUNCATE tenants CASCADE');
});

/**
 * Creates customers with the variant in their cart and runs their checkouts
 * at once. Returns each process's outcome.
 *
 * @return list<array{ok: bool, code?: string, coupon?: string|null}>
 */
function raceCheckouts(Tenant $store, ProductVariant $variant, int $customers, ?Coupon $coupon = null): array
{
    $users = User::factory()->count($customers)->create();
    foreach ($users as $user) {
        $cart = Cart::for($user);
        $cart->update(['coupon_id' => $coupon?->id]);
        CartItem::query()->create(['cart_id' => $cart->id, 'variant_id' => $variant->id, 'quantity' => 1]);
    }

    $startAt = microtime(true) + 2.0;
    $script = base_path('tests/Integration/fixtures/checkout.php');
    $running = [];
    foreach ($users as $user) {
        $process = proc_open(
            [PHP_BINARY, $script, $store->id, $user->id, (string) $startAt],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        assert(is_resource($process));
        $running[] = [$process, $pipes];
    }

    $outcomes = [];
    foreach ($running as [$process, $pipes]) {
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);
        $outcomes[] = json_decode($out, true) ?? ['ok' => false, 'code' => 'NO_OUTPUT', 'message' => $err];
    }

    return $outcomes;
}

it('never sells more units than exist when customers race for the last ones', function (): void {
    $variant = ProductVariant::factory()->create();
    $lot = StockLot::factory()->create(['variant_id' => $variant->id, 'quantity_on_hand' => 3]);

    $outcomes = raceCheckouts($this->store, $variant, customers: 8);

    $codes = collect($outcomes)->map(fn (array $o): string => $o['ok'] ? 'ok' : $o['code'])->countBy()->all();
    expect($codes['ok'] ?? 0)->toBe(3)
        ->and(array_diff(array_keys($codes), ['ok', 'INSUFFICIENT_STOCK', 'CART_NOT_READY']))->toBe([])
        ->and($lot->fresh()?->quantity_reserved)->toBe(3)
        ->and(Order::query()->count())->toBe(3);
});

it('never lets a coupon be used more often than its limit', function (): void {
    $variant = ProductVariant::factory()->create();
    StockLot::factory()->create(['variant_id' => $variant->id, 'quantity_on_hand' => 50]);
    $coupon = Coupon::factory()->create(['max_redemptions' => 2]);

    $outcomes = raceCheckouts($this->store, $variant, customers: 6, coupon: $coupon);

    // Sorted: processes finish in any order.
    $codes = collect($outcomes)->map(fn (array $o): string => $o['ok'] ? 'ok' : $o['code'])->countBy()->sortKeys()->all();
    expect($codes)->toBe(['COUPON_EXHAUSTED' => 4, 'ok' => 2])
        ->and($coupon->fresh()?->times_redeemed)->toBe(2)
        ->and(Order::query()->whereNotNull('coupon_id')->count())->toBe(2);
});
