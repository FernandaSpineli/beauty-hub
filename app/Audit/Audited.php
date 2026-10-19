<?php

declare(strict_types=1);

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Records creates, updates and deletes made by the store's team. The audit
 * name is the model's short name ("product", "coupon").
 *
 * @phpstan-require-extends Model
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn (Model $model) => app(AuditTrail::class)->record($model, 'created', AuditTrail::snapshot($model)));
        static::updated(fn (Model $model) => app(AuditTrail::class)->record($model, 'updated', AuditTrail::diff($model)));
        static::deleted(fn (Model $model) => app(AuditTrail::class)->record($model, 'deleted', []));
    }
}
