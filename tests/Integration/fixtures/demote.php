<?php

declare(strict_types=1);

/*
 * One owner demotes another, in its own PHP process, for the concurrency
 * test. Usage: php demote.php <tenant-id> <actor-id> <member-id> <start-at>
 */

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Tenant;
use App\Models\User;
use App\Stores\TeamRoles;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $tenantId, $actorId, $memberId, $startAt] = $argv;
$app->make(TenantContext::class)->set(Tenant::query()->findOrFail($tenantId));
$actor = User::query()->findOrFail($actorId);
$member = User::query()->findOrFail($memberId);

time_sleep_until((float) $startAt);

try {
    $app->make(TeamRoles::class)->change($actor, $member, UserRole::Staff);
    echo json_encode(['ok' => true]);
} catch (ApiException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'CRASH', 'message' => $e->getMessage()]);
}
