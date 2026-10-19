<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'slug' => substr(fake()->unique()->slug(2), 0, 30).'-'.fake()->randomNumber(4, true),
            'name' => $name,
            'status' => TenantStatus::Active,
        ];
    }

    public function suspended(): self
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }
}
