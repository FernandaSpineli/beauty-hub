<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Emails a reset link if the account exists. Always answers the same way,
 * so the endpoint cannot be used to find out who has an account.
 */
final class ForgotPassword
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{email: string} $data */
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:254']]);

        Password::broker()->sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        return new JsonResponse([
            'message' => 'If an account exists for this email, a reset link is on its way.',
        ], 202);
    }
}
