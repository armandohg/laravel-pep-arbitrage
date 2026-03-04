<?php

namespace App\Rebalance;

use App\Exchanges\ExchangeRegistry;

final class RebalanceService
{
    public function __construct(
        private readonly ExchangeRegistry $registry,
        private readonly TransferRouteService $transferRouteService,
    ) {}

    public function plan(float $tolerance = 0.10, ?string $networkOverride = null): RebalancePlan
    {
        // Fetch balances
        $mexcBalances = $this->registry->get('Mexc')->getBalances();
        $coinexBalances = $this->registry->get('CoinEx')->getBalances();
        $krakenBalances = $this->registry->get('Kraken')->getBalances();

        // Get USDT/USD rate
        $usdtUsdBook = $this->registry->get('Kraken')->getOrderBook('usdt_usd');
        $usdtUsdRate = isset($usdtUsdBook['asks'][0][0]) ? (float) $usdtUsdBook['asks'][0][0] : 1.0;

        $mexcPep = (float) ($mexcBalances['PEP']['available'] ?? 0);
        $mexcUsdt = (float) ($mexcBalances['USDT']['available'] ?? 0);

        $coinexPep = (float) ($coinexBalances['PEP']['available'] ?? 0);
        $coinexUsdt = (float) ($coinexBalances['USDT']['available'] ?? 0);

        $krakenPep = (float) ($krakenBalances['PEP']['available'] ?? 0);
        $krakenUsd = (float) ($krakenBalances['USD']['available'] ?? 0);
        $krakenUsdtNative = (float) ($krakenBalances['USDT']['available'] ?? 0);
        $krakenUsdt = ($krakenUsd / $usdtUsdRate) + $krakenUsdtNative;

        $states = [
            'Mexc' => new ExchangeState('Mexc', $mexcPep, $mexcUsdt, 'USDT', $mexcUsdt),
            'CoinEx' => new ExchangeState('CoinEx', $coinexPep, $coinexUsdt, 'USDT', $coinexUsdt),
            'Kraken' => new ExchangeState('Kraken', $krakenPep, $krakenUsdt, 'USD', $krakenUsd + ($krakenUsdtNative * $usdtUsdRate)),
        ];

        $totalPep = $mexcPep + $coinexPep + $krakenPep;
        $totalUsdt = $mexcUsdt + $coinexUsdt + $krakenUsdt;

        $targetPep = $totalPep / 3;
        $targetUsdt = $totalUsdt / 3;

        $targets = [
            'Mexc' => new ExchangeState('Mexc', $targetPep, $targetUsdt, 'USDT', $targetUsdt),
            'CoinEx' => new ExchangeState('CoinEx', $targetPep, $targetUsdt, 'USDT', $targetUsdt),
            'Kraken' => new ExchangeState('Kraken', $targetPep, $targetUsdt, 'USD', $targetUsdt * $usdtUsdRate),
        ];

        // Check if already balanced
        $isBalanced = $this->checkBalanced($states, $targets, $tolerance);

        if ($isBalanced) {
            return new RebalancePlan(
                states: array_values($states),
                targets: array_values($targets),
                transfers: [],
                isBalanced: true,
                usdtUsdRate: $usdtUsdRate,
            );
        }

        $transfers = array_merge(
            $this->computeTransfers('PEP', $states, $targets, $networkOverride),
            $this->computeTransfers('USDT', $states, $targets, $networkOverride),
        );

        return new RebalancePlan(
            states: array_values($states),
            targets: array_values($targets),
            transfers: $transfers,
            isBalanced: false,
            usdtUsdRate: $usdtUsdRate,
        );
    }

    /**
     * @param  ExchangeState[]  $states
     * @param  ExchangeState[]  $targets
     */
    private function checkBalanced(array $states, array $targets, float $tolerance): bool
    {
        foreach ($states as $exchange => $state) {
            $target = $targets[$exchange];

            if ($target->pep > 0) {
                $pepDeviation = abs($state->pep - $target->pep) / $target->pep;
                if ($pepDeviation > $tolerance) {
                    return false;
                }
            }

            if ($target->quoteUsdt > 0) {
                $usdtDeviation = abs($state->quoteUsdt - $target->quoteUsdt) / $target->quoteUsdt;
                if ($usdtDeviation > $tolerance) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Greedy algorithm to compute transfers for a given currency.
     *
     * @param  ExchangeState[]  $states
     * @param  ExchangeState[]  $targets
     * @return Transfer[]
     */
    private function computeTransfers(string $currency, array $states, array $targets, ?string $networkOverride = null): array
    {
        $isPep = $currency === 'PEP';

        $surpluses = [];
        $deficits = [];

        foreach ($states as $exchange => $state) {
            $actual = $isPep ? $state->pep : $state->quoteUsdt;
            $target = $isPep ? $targets[$exchange]->pep : $targets[$exchange]->quoteUsdt;
            $diff = $actual - $target;

            if ($diff > 0.001) {
                $surpluses[$exchange] = $diff;
            } elseif ($diff < -0.001) {
                $deficits[$exchange] = abs($diff);
            }
        }

        arsort($surpluses);
        arsort($deficits);

        $transfers = [];

        foreach ($surpluses as $fromExchange => $surplus) {
            foreach ($deficits as $toExchange => $deficit) {
                if ($surplus <= 0.001 || $deficit <= 0.001) {
                    continue;
                }

                $amount = min($surplus, $deficit);
                $route = $this->transferRouteService->getRouteForTransfer($fromExchange, $toExchange, $currency, $networkOverride);

                $krakenStep = null;
                if ($fromExchange === 'Kraken' && $currency === 'USDT') {
                    $krakenStep = "buy {$amount} USDT on Kraken first";
                } elseif ($toExchange === 'Kraken' && $currency === 'USDT') {
                    $krakenStep = 'sell USDT→USD after deposit confirms on Kraken';
                }

                $transfers[] = new Transfer(
                    fromExchange: $fromExchange,
                    toExchange: $toExchange,
                    currency: $currency,
                    amount: $amount,
                    network: $route['network_code'],
                    networkId: $route['network_id'],
                    address: $route['address'],
                    networkFee: $route['fee'],
                    memo: $route['memo'],
                    krakenStep: $krakenStep,
                    withdrawKey: $route['withdraw_key'] ?? null,
                );

                $surpluses[$fromExchange] -= $amount;
                $deficits[$toExchange] -= $amount;
            }
        }

        return $transfers;
    }

    public function execute(RebalancePlan $plan): void
    {
        foreach ($plan->transfers as $transfer) {
            // If Kraken needs to buy USDT first
            if ($transfer->krakenStep !== null && str_starts_with($transfer->krakenStep, 'buy')) {
                /** @var \App\Exchanges\Kraken $kraken */
                $kraken = $this->registry->get('Kraken');
                $kraken->buyUsdt($transfer->amount);
            }

            $this->registry->get($transfer->fromExchange)
                ->withdraw($transfer->currency, $transfer->amount, $transfer->address, $transfer->networkId, $transfer->withdrawKey);
        }
    }
}
