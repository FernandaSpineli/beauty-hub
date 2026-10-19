<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordTenantJob;

/*
 * Runs a real worker (queue:work against Redis) in this process, so the
 * same database connection serves job after job, as in production.
 */

beforeEach(function (): void {
    Artisan::call('migrate', ['--database' => 'owner', '--force' => true]);
    config(['queue.default' => 'redis']);
    Queue::connection('redis')->clear('tenant-test');
    $this->store = Tenant::factory()->create();
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
    DB::connection('owner')->statement('TRUNCATE tenants CASCADE');
});

function work(): void
{
    Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'tenant-test', '--once' => true, '--sleep' => 0]);
}

function recorded(string $label): mixed
{
    return Cache::store('redis')->pull("tenant-job:{$label}");
}

it('runs a job as the store it was dispatched for, then forgets it', function (): void {
    // A block, not an arrow function: dispatch() sends the job when its
    // PendingDispatch is destroyed, which must happen inside the store.
    app(TenantContext::class)->run($this->store, function (): void {
        RecordTenantJob::dispatch('store-job')->onQueue('tenant-test');
    });
    RecordTenantJob::dispatch('platform-job')->onQueue('tenant-test');

    work();
    work();

    expect(recorded('store-job'))->toBe(['context' => $this->store->id, 'database' => $this->store->id])
        // Same worker, same connection, next job: no store left behind.
        ->and(recorded('platform-job'))->toBe(['context' => null, 'database' => '']);
});
