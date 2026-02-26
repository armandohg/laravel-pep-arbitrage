<?php

namespace App\Arbitrage\ValueObjects;

readonly class OpportunityData
{
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
