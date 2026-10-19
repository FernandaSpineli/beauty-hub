<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Context;

/**
 * Runs each queued job as the store it was dispatched for.
 *
 * Before a job: the store from the job's Context is set, or the store is
 * explicitly cleared; a worker's database connection may still hold the
 * previous job's store otherwise. After it: whatever was active before is
 * restored. In a worker that is "no store"; for a job run inline (sync
 * queue, dispatchSync) it is the request's store, which must survive.
 *
 * A singleton, not scoped: workers forget scoped instances between jobs.
 */
final class JobTenancy
{
    /** @var list<Tenant|null> */
    private array $previous = [];

    public function __construct(private readonly Container $app) {}

    public function starting(): void
    {
        $context = $this->context();
        $this->previous[] = $context->current();

        $id = Context::get('tenant_id');
        $store = is_string($id) ? Tenant::query()->find($id) : null;

        $store === null ? $context->clear() : $context->set($store);
    }

    public function finished(): void
    {
        $previous = array_pop($this->previous);

        $previous === null ? $this->context()->clear() : $this->context()->set($previous);
    }

    private function context(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }
}
