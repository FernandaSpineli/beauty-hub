<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Revokes the token used for this request, or every token with ?all=1.
 */
final class Logout
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->boolean('all')) {
            $user->tokens()->delete();
        } else {
            $token = $user->currentAccessToken();
            assert($token instanceof PersonalAccessToken);
            $token->delete();
        }

        return response()->noContent();
    }
}
