<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

/**
 * Exchanges email and password for an API token at the current store.
 */
final class Login
{
    use IssuesTokens;

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();
        $password = $request->string('password')->toString();

        if ($user === null) {
            // Spend the same hashing time as a real check, so the response
            // time does not reveal which emails have an account.
            Hash::make($password);

            throw self::invalidCredentials();
        }

        if (! Hash::check($password, $user->password)) {
            throw self::invalidCredentials();
        }

        return $this->tokenResponse($user, $request->string('device_name', 'api')->toString());
    }

    private static function invalidCredentials(): ApiException
    {
        return new ApiException(401, 'INVALID_CREDENTIALS', 'Email or password is incorrect.');
    }
}
