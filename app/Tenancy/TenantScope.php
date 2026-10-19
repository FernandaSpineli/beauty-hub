<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters tenant-scoped models by the current tenant. Row-level security
 * already guarantees this in the database; the scope keeps the intent
 * visible in the SQL and lets the planner use the tenant_id indexes.
 *
 * @implements Scope<Model>
 */
final readonly class TenantScope implements Scope
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            $builder->whereRaw('false');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
