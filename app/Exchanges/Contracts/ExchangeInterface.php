<?php

namespace App\Exchanges\Contracts;

interface ExchangeInterface
{
    public function getName(): string;

    /**
     * Returns normalized order book.
     *
     * @return array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}
     */
    public function getOrderBook(string $symbol): array;

    /**
     * Returns balances keyed by currency.
     *
     * @return array<string, array{available: float}>
     */
    public function getBalances(): array;

    /** Returns maker/taker fee rate as decimal (e.g. 0.002 = 0.2%) */
    public function getTxFee(): float;
}
