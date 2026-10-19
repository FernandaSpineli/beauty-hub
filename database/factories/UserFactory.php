<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Creates users in the current tenant (see App\Tenancy\TenantContext).
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password123'),
            'role' => UserRole::Customer,
            'email_verified_at' => now(),
        ];
    }

    public function owner(): self
    {
        return $this->state(['role' => UserRole::Owner]);
    }

    public function staff(): self
    {
        return $this->state(['role' => UserRole::Staff]);
    }
}
