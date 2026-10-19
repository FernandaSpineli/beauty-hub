<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $action
 * @property string $subject_type
 * @property string $subject_id
 * @property array<string, array{0: mixed, 1: mixed}> $changes
 * @property string|null $request_id
 * @property Carbon $created_at
 * @property-read User|null $user
 */
final class AuditLog extends Model
{
    use BelongsToTenant, HasUuids;

    public const null UPDATED_AT = null;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
