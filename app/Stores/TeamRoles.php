<?php

declare(strict_types=1);

namespace App\Stores;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Changes a team member's role. A store always keeps at least one owner,
 * also when two owners demote each other at the same moment, and owners
 * cannot change their own role.
 */
final class TeamRoles
{
    public function change(User $actor, User $member, UserRole $role): User
    {
        if ($member->id === $actor->id) {
            throw new ApiException(409, 'CANNOT_CHANGE_OWN_ROLE', 'Ask another owner to change your role.');
        }

        return DB::transaction(function () use ($actor, $member, $role): User {
            // Locking every owner row serialises concurrent changes, and the
            // author must still be an owner once the lock is held. As authors
            // never change themselves, an owner always remains: two owners
            // demoting each other at once end with one of them refused.
            $owners = User::query()->where('role', UserRole::Owner)->lockForUpdate()->pluck('id');

            if (! $owners->contains($actor->id)) {
                throw new ApiException(403, 'FORBIDDEN', 'You are not allowed to do this.');
            }

            $member = User::query()->findOrFail($member->id);
            if (! $member->role->managesStore()) {
                throw new ApiException(404, 'NOT_FOUND', 'Resource not found.');
            }
            $member->role = $role;
            $member->save();

            return $member;
        });
    }
}
