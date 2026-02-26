<?php

namespace App\Arbitrage;

use App\Arbitrage\ValueObjects\OpportunityData;
use App\Exchanges\Contracts\ExchangeInterface;

class DetectOpportunity
{
    public const float MIN_PROFIT_RATIO = 0.003; // 0.3%

    /**
     * Check both directions (A→B and B→A) and return the best opportunity, or null.
     */
    public function between(
        ExchangeInterface $exchangeA, array $bookA,
        ExchangeInterface $exchangeB, array $bookB,
        float $minProfitRatio = self::MIN_PROFIT_RATIO,
    ): ?OpportunityData {
        $aToB = $this->detect($exchangeA, $bookA, $exchangeB, $bookB, $minProfitRatio);
        $bToA = $this->detect($exchangeB, $bookB, $exchangeA, $bookA, $minProfitRatio);

        if ($aToB === null && $bToA === null) {
            return null;
        }

        if ($aToB === null) {
            return $bToA;
        }

        if ($bToA === null) {
            return $aToB;
        }

        return $aToB->profitRatio >= $bToA->profitRatio ? $aToB : $bToA;
    }

    /**
     * One direction: buy on $buyExchange (walks asks ascending), sell on $sellExchange (walks bids descending).
     *
     * @param  array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}  $buyBook
     * @param  array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}  $sellBook
     */
    public function detect(
        ExchangeInterface $buyExchange, array $buyBook,
        ExchangeInterface $sellExchange, array $sellBook,
        float $minProfitRatio = self::MIN_PROFIT_RATIO,
    ): ?OpportunityData {
        $asks = $buyBook['asks'];
        $bids = $sellBook['bids'];

        usort($asks, fn (array $a, array $b) => $a['price'] <=> $b['price']);
        usort($bids, fn (array $a, array $b) => $b['price'] <=> $a['price']);

        $buyFee = $buyExchange->getTxFee();
        $sellFee = $sellExchange->getTxFee();

        // Quick-exit: top bid net revenue vs top ask net cost
        if (empty($asks) || empty($bids)) {
            return null;
        }

        $topAskCostPerUnit = $asks[0]['price'] * (1 + $buyFee);
        $topBidRevenuePerUnit = $bids[0]['price'] * (1 - $sellFee);

        if ($topBidRevenuePerUnit <= $topAskCostPerUnit) {
            return null;
        }

        $totalAmount = 0.0;
        $totalBuyCost = 0.0;
        $totalSellRevenue = 0.0;

        $askIndex = 0;
        $bidIndex = 0;
        $askRemaining = $asks[0]['amount'];
        $bidRemaining = $bids[0]['amount'];

        while ($askIndex < count($asks) && $bidIndex < count($bids)) {
            $askPrice = $asks[$askIndex]['price'];
            $bidPrice = $bids[$bidIndex]['price'];

            $costPerUnit = $askPrice * (1 + $buyFee);
            $revenuePerUnit = $bidPrice * (1 - $sellFee);

            if ($revenuePerUnit <= $costPerUnit) {
                break;
            }

            $filled = min($askRemaining, $bidRemaining);

            $totalAmount += $filled;
            $totalBuyCost += $askPrice * $filled * (1 + $buyFee);
            $totalSellRevenue += $bidPrice * $filled * (1 - $sellFee);

            $askRemaining -= $filled;
            $bidRemaining -= $filled;

            if ($askRemaining <= 0) {
                $askIndex++;
                if ($askIndex < count($asks)) {
                    $askRemaining = $asks[$askIndex]['amount'];
                }
            }

            if ($bidRemaining <= 0) {
                $bidIndex++;
                if ($bidIndex < count($bids)) {
                    $bidRemaining = $bids[$bidIndex]['amount'];
                }
            }
        }

        if ($totalAmount <= 0) {
            return null;
        }

        $profit = $totalSellRevenue - $totalBuyCost;
        $profitRatio = $profit / $totalBuyCost;

        if ($profitRatio < $minProfitRatio) {
            return null;
        }

        return new OpportunityData(
            buyExchange: $buyExchange->getName(),
            sellExchange: $sellExchange->getName(),
            amount: $totalAmount,
            totalBuyCost: $totalBuyCost,
            totalSellRevenue: $totalSellRevenue,
            profit: $profit,
            profitRatio: $profitRatio,
            profitLevel: OpportunityData::profitLevel($profitRatio),
        );
    }

    /**
     * Normalize a Kraken USD order book to USDT-equivalent prices.
     * usdtUsdRate = price of 1 USDT in USD (e.g. 0.999 means 1 USDT = $0.999).
     * kraken_usdt_price = kraken_usd_price / usdtUsdRate
     *
     * @param  array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}  $orderBook
     * @return array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}
     */
    public static function normalizeToUsdt(array $orderBook, float $usdtUsdRate): array
    {
        $normalize = fn (array $levels) => array_map(
            fn (array $level) => ['price' => $level['price'] / $usdtUsdRate, 'amount' => $level['amount']],
            $levels,
        );

        return [
            'bids' => $normalize($orderBook['bids']),
            'asks' => $normalize($orderBook['asks']),
        ];
    }
}
