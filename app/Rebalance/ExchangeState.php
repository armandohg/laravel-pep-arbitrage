<?php

namespace App\Rebalance;

final readonly class ExchangeState
{
    public function __construct(
        public string $exchange,
        public float $pep,
        public float $quoteUsdt,
        public string $quoteCurrency,
        public float $quoteNative,
    ) {}
}
