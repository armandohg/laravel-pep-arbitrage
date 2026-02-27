<?php

namespace App\Arbitrage\ValueObjects;

readonly class OpportunityData
{
    /**
     * @param  array<int, array{price: float, amount: float}>  $buyLevels  Ask levels used (price ascending).
     * @param  array<int, array{price: float, amount: float}>  $sellLevels  Bid levels used (price descending).
     */
    public function __construct(
        public string $buyExchange,
        public string $sellExchange,
        public float $amount,
        public float $avgBuyPrice,
        public float $avgSellPrice,
        public float $totalBuyCost,
        public float $totalSellRevenue,
        public float $profit,
        public float $profitRatio,
        public string $profitLevel,
        public array $buyLevels = [],
        public array $sellLevels = [],
    ) {}

    public static function profitLevel(float $ratio): string
    {
        return match (true) {
            $ratio >= 0.10 => 'Extreme',
            $ratio >= 0.05 => 'VeryHigh',
            $ratio >= 0.03 => 'High',
            $ratio >= 0.01 => 'Medium',
            default => 'Low',
        };
    }
}
