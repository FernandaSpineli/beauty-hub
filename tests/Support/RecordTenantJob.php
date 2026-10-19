<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Records which store a queued job ran as, in PHP and in the database
 * session, so tests can check what a worker did.
 */
final class RecordTenantJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $label) {}

    public function handle(TenantContext $context): void
    {
        Cache::store('redis')->put("tenant-job:{$this->label}", [
            'context' => $context->id(),
            'database' => DB::scalar("SELECT current_setting('app.tenant_id', true)"),
        ], 60);
    }
}
