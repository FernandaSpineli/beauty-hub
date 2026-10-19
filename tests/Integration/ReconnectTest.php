<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

it('copies the current tenant into a new database session after a reconnect', function (): void {
    $context = app(TenantContext::class);
    $tenant = new Tenant;
    $tenant->id = '0199f0c4-0000-7000-8000-000000000001';

    $context->set($tenant);
    DB::reconnect();

    expect(DB::scalar("SELECT current_setting('app.tenant_id', true)"))->toBe($tenant->id);

    $context->clear();
    expect(DB::scalar("SELECT current_setting('app.tenant_id', true)"))->toBe('');
});
