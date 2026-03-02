<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BalanceSnapshot>
 */
class BalanceSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'currency' => fake()->randomElement(['PEP', 'USDT', 'USD']),
            'total_available' => fake()->randomFloat(8, 0, 1_000_000),
            'snapped_at' => fake()->dateTimeBetween('-14 days', 'now'),
        ];
    }
}
