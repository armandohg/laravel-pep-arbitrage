<?php

namespace App\Rebalance;

final readonly class Transfer
{
    public function __construct(
        public string $fromExchange,
        public string $toExchange,
        public string $currency,
        public float $amount,
        public string $network,
        public float $networkFee,
        public ?string $krakenStep = null,
    ) {}
}
