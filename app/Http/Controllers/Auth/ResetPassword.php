<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Sets a new password from an emailed token and signs out every device.
 */
final class ResetPassword
{
    public function __invoke(Request $request): Response
    {
        /** @var array{email: string, token: string, password: string} $data */
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'token' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));

        $status = Password::broker()->reset($data, function (User $user, string $password): void {
            $user->password = $password;
            $user->save();
            $user->tokens()->delete();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException(422, 'INVALID_RESET_TOKEN', 'This reset link is invalid or has expired.');
        }

        return response()->noContent();
    }
}
