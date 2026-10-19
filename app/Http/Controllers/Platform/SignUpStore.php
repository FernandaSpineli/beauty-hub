<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Exceptions\ApiException;
use App\Http\Resources\UserResource;
use App\Stores\OpenStore;
use App\Tenancy\ReservedSlugs;
use App\Tenancy\StoreUrl;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /api/v1/stores on the platform domain: anyone can open a store and
 * becomes its owner, signed in at the new store.
 */
final class SignUpStore
{
    public function __invoke(Request $request, OpenStore $openStore, TenantContext $context): JsonResponse
    {
        if (is_string($request->input('slug'))) {
            $request->merge(['slug' => mb_strtolower(trim($request->input('slug')))]);
        }

        /** @var array{slug: string, name: string, owner_name: string, owner_email: string, password: string} $data */
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:/\A[a-z0-9]([a-z0-9-]{1,38}[a-z0-9])\z/',
                Rule::notIn(ReservedSlugs::ALL), Rule::unique('tenants', 'slug')],
            'name' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        try {
            [$store, $owner] = $openStore->handle(
                $data['slug'], $data['name'], $data['owner_name'], mb_strtolower(trim($data['owner_email'])), $data['password'],
            );
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups raced for the same address.
            throw new ApiException(422, 'VALIDATION_FAILED', 'Some fields are invalid.', [
                ['field' => 'slug', 'message' => 'The slug has already been taken.'],
            ]);
        }

        $token = $context->run($store, fn (): string => $owner->createToken('sign-up')->plainTextToken);

        return new JsonResponse(['data' => [
            'store' => ['slug' => $store->slug, 'name' => $store->name, 'url' => StoreUrl::to($store)],
            'owner' => $context->run($store, fn (): array => (new UserResource($owner))->resolve()),
            'token' => $token,
        ]], 201);
    }
}
