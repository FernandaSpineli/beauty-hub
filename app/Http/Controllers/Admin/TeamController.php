<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Stores\TeamRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Owners manage who runs the store (role rules in TeamRoles).
 */
final class TeamController
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('own-store');

        return UserResource::collection(
            User::query()->whereIn('role', [UserRole::Owner, UserRole::Staff])->orderBy('name')->get(),
        );
    }

    /**
     * Invites someone by email. An existing customer of the store is
     * promoted; anyone else gets an account and a link to set a password.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('own-store');
        $request->merge(['email' => is_string($request->input('email')) ? mb_strtolower(trim($request->input('email'))) : $request->input('email')]);

        /** @var array{name: string, email: string, role: string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'role' => ['required', Rule::in([UserRole::Owner->value, UserRole::Staff->value])],
        ]);

        $member = User::query()->firstOrNew(['email' => $data['email']]);
        if ($member->exists && $member->role->managesStore()) {
            throw new ApiException(409, 'ALREADY_IN_TEAM', 'This person is already on the team.');
        }

        $member->name = $member->exists ? $member->name : $data['name'];
        $member->password = $member->exists ? $member->password : Str::password(32);
        $member->role = UserRole::from($data['role']);
        $member->save();

        $inviter = $this->actor($request);
        $member->notify(new TeamInvitationNotification(Password::broker()->createToken($member), $inviter->name));

        return (new UserResource($member))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $member, TeamRoles $roles): UserResource
    {
        Gate::authorize('own-store');
        /** @var array{role: string} $data */
        $data = $request->validate(['role' => ['required', Rule::in([UserRole::Owner->value, UserRole::Staff->value])]]);

        return new UserResource($roles->change($this->actor($request), $member, UserRole::from($data['role'])));
    }

    /**
     * Removes someone from the team: their account stays, as a customer, and
     * every session they had is signed out.
     */
    public function destroy(Request $request, User $member, TeamRoles $roles): Response
    {
        Gate::authorize('own-store');
        $roles->change($this->actor($request), $member, UserRole::Customer)->tokens()->delete();

        return response()->noContent();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
