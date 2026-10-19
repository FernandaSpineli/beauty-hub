<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiException;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;

/**
 * Creates a customer account at the current store and signs it in.
 */
final class Register
{
    use IssuesTokens;

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        try {
            /** @var array{name: string, email: string, password: string} $data */
            $data = $request->safe()->only(['name', 'email', 'password']);
            $user = User::query()->create($data);
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups with the same email raced past validation.
            throw new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', [
                ['field' => 'email', 'message' => 'The email has already been taken.'],
            ]);
        }

        return $this->tokenResponse($user, $request->string('device_name', 'api')->toString(), 201);
    }
}
