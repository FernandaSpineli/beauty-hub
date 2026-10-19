<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models stored in tenant-scoped tables (tables with a tenant_id column
 * and row-level security). New rows take the current tenant, and a row can
 * never be moved to another tenant.
 *
 * @property string $tenant_id
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(app(TenantScope::class));

        static::creating(function (Model $model): void {
            $tenant = app(TenantContext::class)->require();

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $tenant->id);
            } elseif ($model->getAttribute('tenant_id') !== $tenant->id) {
                throw new NoTenantContext('Cannot create a row for another tenant.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new NoTenantContext('A row cannot move to another tenant.');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
