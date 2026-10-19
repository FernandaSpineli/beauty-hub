<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CouponKind;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
final class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('BELEZA##??')),
            'kind' => CouponKind::Percent,
            'value' => 10,
        ];
    }
}
