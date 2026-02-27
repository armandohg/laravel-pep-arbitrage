<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExchangeNetwork>
 */
class ExchangeNetworkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exchange' => fake()->randomElement(['Mexc', 'CoinEx', 'Kraken']),
            'asset' => fake()->randomElement(['PEP', 'USDT']),
            'network_code' => fake()->randomElement(['PEP', 'TRC20', 'ERC20']),
            'network_id' => fake()->randomElement(['PEP', 'TRX', 'ETH']),
            'network_name' => fake()->word(),
            'fee' => 1.0,
            'min_amount' => 0.0,
            'max_amount' => 0.0,
            'deposit_enabled' => true,
            'withdraw_enabled' => true,
        ];
    }
}
