<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Writes audit entries for changes made by a signed-in owner or staff
 * member. Customers' own actions and system work are not audited here:
 * orders and stock keep their own history.
 */
final readonly class AuditTrail
{
    /** Never written to the log, whatever the model. */
    private const array IGNORED = ['password', 'remember_token', 'created_at', 'updated_at', 'search_text', 'tenant_id'];

    public function __construct(
        private Auth $auth,
        private TenantContext $context,
    ) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public function record(Model $subject, string $action, array $changes): void
    {
        // Only a user the request already authenticated: resolving one here
        // would make the guard authenticate (and cache) as a side effect.
        $guard = $this->auth->guard('sanctum');
        $actor = $guard->hasUser() ? $guard->user() : null;
        $store = $this->context->id();
        if (! $actor instanceof User || ! $actor->role->managesStore() || $store === null || $actor->tenant_id !== $store) {
            return;
        }
        if ($action === 'updated' && $changes === []) {
            return;
        }

        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => Str::snake(class_basename($subject)),
            'subject_id' => is_scalar($key = $subject->getKey()) ? (string) $key : '',
            'changes' => $changes,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function snapshot(Model $model): array
    {
        $changes = [];
        foreach ($model->getAttributes() as $key => $value) {
            if (! in_array($key, self::IGNORED, true)) {
                $changes[$key] = [null, $value];
            }
        }

        return $changes;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function diff(Model $model): array
    {
        $changes = [];
        foreach ($model->getChanges() as $key => $value) {
            if (! in_array($key, self::IGNORED, true)) {
                $changes[$key] = [$model->getOriginal($key), $value];
            }
        }

        return $changes;
    }
}
