<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

trait IssuesTokens
{
    private function tokenResponse(User $user, string $deviceName, int $status = 200): JsonResponse
    {
        $expiresAt = now()->addMinutes(config()->integer('sanctum.expiration'));
        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        return new JsonResponse(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => new UserResource($user),
        ]], $status);
    }
}
