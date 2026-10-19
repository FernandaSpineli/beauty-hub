<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * GET /admin/audit-log?subject_type=product&subject_id=...&user_id=...
 * What the team changed, newest first. Owners only.
 */
final class AuditLogController
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('own-store');
        $request->validate([
            'subject_type' => ['sometimes', 'string', 'max:40'],
            'subject_id' => ['sometimes', 'string', 'max:64'],
            'user_id' => ['sometimes', 'uuid'],
        ]);

        $entries = AuditLog::query()
            ->with('user:id,name,email')
            ->when($request->query('subject_type'), fn ($q, $v) => $q->where('subject_type', $v))
            ->when($request->query('subject_id'), fn ($q, $v) => $q->where('subject_id', $v))
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50);

        return JsonResource::collection($entries->through(fn (AuditLog $entry): array => [
            'id' => $entry->id,
            'action' => $entry->action,
            'subject' => ['type' => $entry->subject_type, 'id' => $entry->subject_id],
            'changes' => $entry->changes,
            'by' => $entry->user === null ? null : ['id' => $entry->user->id, 'name' => $entry->user->name],
            'request_id' => $entry->request_id,
            'at' => $entry->created_at->toIso8601String(),
        ]));
    }
}
