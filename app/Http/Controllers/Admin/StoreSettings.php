<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The store's own settings. Staff can read them; only owners change them.
 */
final readonly class StoreSettings
{
    public function __construct(private TenantContext $context) {}

    public function show(): JsonResponse
    {
        return $this->response();
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('own-store');

        /** @var array{name?: string, min_shelf_life_days?: int} $data */
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'min_shelf_life_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);
        $this->context->require()->update($data);

        return $this->response();
    }

    private function response(): JsonResponse
    {
        $store = $this->context->require();

        return new JsonResponse(['data' => [
            'slug' => $store->slug,
            'name' => $store->name,
            'min_shelf_life_days' => $store->min_shelf_life_days,
        ]]);
    }
}
