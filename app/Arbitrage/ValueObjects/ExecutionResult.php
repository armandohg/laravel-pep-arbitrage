<?php

namespace App\Arbitrage\ValueObjects;

readonly class ExecutionResult
{
    public function __construct(
        public bool $success,
        public ?string $buyOrderId,
        public ?string $sellOrderId,
        /** @var 'buy'|'sell'|null */
        public ?string $failedSide,
        public ?string $error,
    ) {}
}
