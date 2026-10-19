<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Exceptions\ApiException;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Changes the password and signs out every other device.
 */
final class ChangePassword
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{current_password: string, password: string} $data */
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', [
                ['field' => 'current_password', 'message' => 'The current password is incorrect.'],
            ]);
        }

        $user->password = $data['password'];
        $user->save();

        $current = $user->currentAccessToken();
        assert($current instanceof PersonalAccessToken);
        $user->tokens()->whereKeyNot($current->getKey())->delete();

        return response()->noContent();
    }
}
