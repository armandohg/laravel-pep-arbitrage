<?php

namespace Database\Factories;

use App\Arbitrage\ValueObjects\OpportunityData;
use App\Models\ArbitrageOpportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArbitrageOpportunity>
 */
class ArbitrageOpportunityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $exchanges = ['Mexc', 'CoinEx', 'Kraken'];
        $buyExchange = fake()->randomElement($exchanges);
        $sellExchange = fake()->randomElement(array_values(array_filter($exchanges, fn ($e) => $e !== $buyExchange)));

        $amount = fake()->randomFloat(0, 500000, 5000000);
        $profitRatio = fake()->randomFloat(8, 0.003, 0.15);
        $totalBuyCost = fake()->randomFloat(8, 10, 500);
        $totalSellRevenue = round($totalBuyCost * (1 + $profitRatio), 8);
        $profit = round($totalSellRevenue - $totalBuyCost, 8);

        return [
            'buy_exchange' => $buyExchange,
            'sell_exchange' => $sellExchange,
            'amount' => $amount,
            'total_buy_cost' => $totalBuyCost,
            'total_sell_revenue' => $totalSellRevenue,
            'profit' => $profit,
            'profit_ratio' => $profitRatio,
            'profit_level' => OpportunityData::profitLevel($profitRatio),
        ];
    }

    public function profitable(float $ratio): static
    {
        return $this->state(function () use ($ratio) {
            $totalBuyCost = fake()->randomFloat(8, 10, 500);
            $totalSellRevenue = round($totalBuyCost * (1 + $ratio), 8);

            return [
                'profit_ratio' => $ratio,
                'profit_level' => OpportunityData::profitLevel($ratio),
                'total_buy_cost' => $totalBuyCost,
                'total_sell_revenue' => $totalSellRevenue,
                'profit' => round($totalSellRevenue - $totalBuyCost, 8),
            ];
        });
    }

    public function extreme(): static
    {
        return $this->profitable(fake()->randomFloat(8, 0.10, 0.50));
    }
}
