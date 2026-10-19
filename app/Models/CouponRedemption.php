<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $coupon_id
 * @property string $user_id
 * @property string $order_id
 */
final class CouponRedemption extends Model
{
    use BelongsToTenant, HasUuids;

    public const null UPDATED_AT = null;

    protected $guarded = ['tenant_id'];
}
