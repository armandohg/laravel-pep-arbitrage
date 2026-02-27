<?php

namespace App\Rebalance;

final readonly class RebalancePlan
{
    /**
     * @param  ExchangeState[]  $states
     * @param  ExchangeState[]  $targets
     * @param  Transfer[]  $transfers
     */
    public function __construct(
        public array $states,
        public array $targets,
        public array $transfers,
        public bool $isBalanced,
        public float $usdtUsdRate,
    ) {}
}
