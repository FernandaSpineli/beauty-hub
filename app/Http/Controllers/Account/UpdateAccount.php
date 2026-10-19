<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Updates the profile. The email is not editable: changing it safely needs a
 * confirmation flow.
 */
final class UpdateAccount
{
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{name: string} $data */
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $user->update($data);

        return new UserResource($user);
    }
}
