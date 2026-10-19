<?php

declare(strict_types=1);

/*
 * Places one order in its own PHP process, for the concurrency tests.
 * Usage: php checkout.php <tenant-id> <user-id> <start-at unix time>
 * Prints a JSON outcome. Every process waits for the same start time, so
 * their transactions really overlap.
 */

use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Models\User;
use App\Orders\PlaceOrder;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $tenantId, $userId, $startAt] = $argv;

$app->make(TenantContext::class)->set(Tenant::query()->findOrFail($tenantId));
$user = User::query()->findOrFail($userId);
$address = ['postal_code' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1', 'district' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP'];

time_sleep_until((float) $startAt);

try {
    [$order] = $app->make(PlaceOrder::class)->handle($user, $address, null, 'concurrency');
    echo json_encode(['ok' => true, 'coupon' => $order->coupon_code]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'CRASH', 'message' => $e->getMessage()]);
}
