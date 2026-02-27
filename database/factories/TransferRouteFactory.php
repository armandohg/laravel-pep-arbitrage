<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TransferRoute>
 */
class TransferRouteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_exchange' => fake()->randomElement(['Mexc', 'CoinEx', 'Kraken']),
            'to_exchange' => fake()->randomElement(['Mexc', 'CoinEx', 'Kraken']),
            'asset' => fake()->randomElement(['PEP', 'USDT']),
            'network_code' => fake()->randomElement(['PEP', 'TRC20', 'ERC20']),
            'wallet_id' => \App\Models\ExchangeWallet::factory(),
            'fee' => 1.0,
            'is_active' => true,
        ];
    }
}
