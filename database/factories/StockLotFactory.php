<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockLot>
 */
final class StockLotFactory extends Factory
{
    protected $model = StockLot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'variant_id' => ProductVariant::factory(),
            'lot_code' => 'L'.fake()->unique()->numerify('#####'),
            'expires_on' => today()->addYear(),
            'quantity_on_hand' => 10,
        ];
    }

    public function expiringIn(int $days): self
    {
        return $this->state(['expires_on' => today()->addDays($days)]);
    }
}
