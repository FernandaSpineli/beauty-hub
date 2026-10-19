<?php

declare(strict_types=1);

namespace App\Stores;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates a store and its owner account in one transaction.
 */
final readonly class OpenStore
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return array{0: Tenant, 1: User}
     */
    public function handle(string $slug, string $name, string $ownerName, string $ownerEmail, string $password): array
    {
        return DB::transaction(function () use ($slug, $name, $ownerName, $ownerEmail, $password): array {
            $store = Tenant::query()->create(['slug' => $slug, 'name' => $name]);

            $owner = $this->context->run($store, function () use ($ownerName, $ownerEmail, $password): User {
                $owner = new User(['name' => $ownerName, 'email' => $ownerEmail, 'password' => $password]);
                $owner->role = UserRole::Owner;
                $owner->save();

                return $owner;
            });

            return [$store, $owner];
        });
    }
}
