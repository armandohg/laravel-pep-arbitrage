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

    /**
     * Returns all available USDT (or USD for Kraken) spot markets with 24h stats.
     *
     * @return array<int, array{symbol: string, base: string, quote_volume_24h: float, price_change_pct: float, last_price: float}>
     */
    public function getAvailableMarkets(): array;

    /**
     * Place a spot order on this exchange.
     *
     * @return array{orderId: string}
     */
    public function placeOrder(string $symbol, string $side, float $amount, string $type, ?float $price = null): array;

    /**
     * Withdraw currency to a destination address or key.
     * For Kraken, $address is a pre-configured withdrawal key name (set in Kraken UI).
     *
     * @return array{withdrawal_id: string|null, raw: array<string, mixed>}
     */
    public function withdraw(string $currency, float $amount, string $address, string $network, ?string $withdrawKey = null): array;

    /**
     * Poll the status of a withdrawal by its exchange-side ID.
     *
     * @return array{status: 'pending'|'processing'|'completed'|'failed', tx_hash: string|null}
     */
    public function getWithdrawalStatus(string $withdrawalId): array;

    /**
     * Poll the status of an inbound deposit on this exchange by blockchain tx hash.
     *
     * @return array{status: 'pending'|'confirming'|'completed'|'failed', amount: float|null}
     */
    public function getDepositStatus(string $txHash): array;
}
