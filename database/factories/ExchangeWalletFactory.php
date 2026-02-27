<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExchangeWallet>
 */
class ExchangeWalletFactory extends Factory
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
            'address' => fake()->sha256(),
            'memo' => null,
            'is_active' => true,
        ];
    }
}
