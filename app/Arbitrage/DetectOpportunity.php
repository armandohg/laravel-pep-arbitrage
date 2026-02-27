<?php

namespace App\Arbitrage;

use App\Arbitrage\ValueObjects\OpportunityData;
use App\Exchanges\Contracts\ExchangeInterface;

class DetectOpportunity
{
    public const float MIN_PROFIT_RATIO = 0.003; // 0.3%

    public const int MAX_LEVELS = 5;

    /**
     * Check both directions (A→B and B→A) and return the best opportunity, or null.
     *
     * Pass the quote (USDT/USD) and base (PEP) balances per exchange to cap each
     * direction by the available balance. Null values = pretend / simulation mode
     * (no balance cap applied).
     */
    public function between(
        ExchangeInterface $exchangeA, array $bookA,
        ExchangeInterface $exchangeB, array $bookB,
        float $minProfitRatio = self::MIN_PROFIT_RATIO,
        ?float $quoteBalanceA = null,
        ?float $baseBalanceA = null,
        ?float $quoteBalanceB = null,
        ?float $baseBalanceB = null,
        int $maxLevels = self::MAX_LEVELS,
    ): ?OpportunityData {
        // A→B: buy on A (spend quoteA), sell on B (spend baseB)
        $aToB = $this->detect($exchangeA, $bookA, $exchangeB, $bookB, $minProfitRatio, $quoteBalanceA, $baseBalanceB, $maxLevels);
        // B→A: buy on B (spend quoteB), sell on A (spend baseA)
        $bToA = $this->detect($exchangeB, $bookB, $exchangeA, $bookA, $minProfitRatio, $quoteBalanceB, $baseBalanceA, $maxLevels);

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
     * Iterates all combinations of 1..maxLevels depth on both sides and returns the
     * combination that yields the highest absolute profit above $minProfitRatio.
     *
     * $quoteBalance caps buy-side fills (e.g. USDT available). Null = no cap.
     * $baseBalance  caps sell-side fills (e.g. PEP available).  Null = no cap.
     *
     * @param  array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}  $buyBook
     * @param  array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}  $sellBook
     */
    public function detect(
        ExchangeInterface $buyExchange, array $buyBook,
        ExchangeInterface $sellExchange, array $sellBook,
        float $minProfitRatio = self::MIN_PROFIT_RATIO,
        ?float $quoteBalance = null,
        ?float $baseBalance = null,
        int $maxLevels = self::MAX_LEVELS,
    ): ?OpportunityData {
        $asks = $buyBook['asks'];
        $bids = $sellBook['bids'];

        usort($asks, fn (array $a, array $b) => $a['price'] <=> $b['price']);
        usort($bids, fn (array $a, array $b) => $b['price'] <=> $a['price']);

        if (empty($asks) || empty($bids)) {
            return null;
        }

        $buyFee = $buyExchange->getTxFee();
        $sellFee = $sellExchange->getTxFee();

        // Quick exit: top bid net revenue must beat top ask net cost
        $topAskCostPerUnit = $asks[0]['price'] * (1 + $buyFee);
        $topBidRevenuePerUnit = $bids[0]['price'] * (1 - $sellFee);

        if ($topBidRevenuePerUnit <= $topAskCostPerUnit) {
            return null;
        }

        $maxBuyableAsks = $this->getMaxBuyableAsks($asks, $quoteBalance);
        $maxSellableBids = $this->getMaxSellableBids($bids, $baseBalance);

        if (empty($maxBuyableAsks) || empty($maxSellableBids)) {
            return null;
        }

        $bestProfit = 0.0;
        $bestOpportunity = null;
        $buyLevelsMax = min($maxLevels, count($maxBuyableAsks));
        $sellLevelsMax = min($maxLevels, count($maxSellableBids));

        for ($buyLevels = 1; $buyLevels <= $buyLevelsMax; $buyLevels++) {
            for ($sellLevels = 1; $sellLevels <= $sellLevelsMax; $sellLevels++) {
                $buySlice = array_slice($maxBuyableAsks, 0, $buyLevels);
                $sellSlice = array_slice($maxSellableBids, 0, $sellLevels);

                $maxAmountToExecute = min(
                    array_sum(array_column($buySlice, 'amount')),
                    array_sum(array_column($sellSlice, 'amount')),
                );

                if ($maxAmountToExecute <= 0) {
                    continue;
                }

                $buyCost = $this->computeBuyCost($buyFee, $maxAmountToExecute, $buySlice);
                $sellRevenue = $this->computeSellRevenue($sellFee, $maxAmountToExecute, $sellSlice);

                if ($buyCost <= 0 || $sellRevenue <= $buyCost) {
                    continue;
                }

                $profit = $sellRevenue - $buyCost;
                $profitRatio = $profit / $buyCost;

                if ($profitRatio < $minProfitRatio || $profit <= $bestProfit) {
                    continue;
                }

                $rawBuy = $this->computeRawValue($maxAmountToExecute, $buySlice);
                $rawSell = $this->computeRawValue($maxAmountToExecute, $sellSlice);

                $bestProfit = $profit;
                $bestOpportunity = new OpportunityData(
                    buyExchange: $buyExchange->getName(),
                    sellExchange: $sellExchange->getName(),
                    amount: $maxAmountToExecute,
                    avgBuyPrice: $rawBuy / $maxAmountToExecute,
                    avgSellPrice: $rawSell / $maxAmountToExecute,
                    totalBuyCost: $buyCost,
                    totalSellRevenue: $sellRevenue,
                    profit: $profit,
                    profitRatio: $profitRatio,
                    profitLevel: OpportunityData::profitLevel($profitRatio),
                    buyLevels: $this->trimLevels($buySlice, $maxAmountToExecute),
                    sellLevels: $this->trimLevels($sellSlice, $maxAmountToExecute),
                );
            }
        }

        return $bestOpportunity;
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

    /**
     * Trim a levels slice so the last level reflects only what is actually consumed
     * up to $maxAmount. Used to build the exact levels stored in OpportunityData.
     *
     * @param  array<int, array{price: float, amount: float}>  $levels
     * @return array<int, array{price: float, amount: float}>
     */
    private function trimLevels(array $levels, float $maxAmount): array
    {
        $result = [];
        $remaining = $maxAmount;

        foreach ($levels as $level) {
            $actual = min($remaining, $level['amount']);

            if ($actual <= 0) {
                break;
            }

            $result[] = ['price' => $level['price'], 'amount' => $actual];
            $remaining -= $actual;

            if ($remaining <= 0) {
                break;
            }
        }

        return $result;
    }

    /**
     * Build a list of fillable ask levels constrained by the available quote currency balance.
     * For each ask, computes how many units can be purchased with the remaining balance.
     * When $quoteBalance is null (pretend mode), all ask levels are returned as-is.
     *
     * @param  array<int, array{price: float, amount: float}>  $asks  Sorted ascending by price.
     * @return array<int, array{price: float, amount: float}>
     */
    private function getMaxBuyableAsks(array $asks, ?float $quoteBalance): array
    {
        if ($quoteBalance === null) {
            return $asks;
        }

        $remaining = $quoteBalance;
        $result = [];

        foreach ($asks as $ask) {
            if ($ask['price'] <= 0 || $remaining <= 0) {
                break;
            }

            $maxPurchase = $remaining / $ask['price'];
            $actual = min($maxPurchase, $ask['amount']);

            if ($actual <= 0) {
                break;
            }

            $result[] = ['price' => $ask['price'], 'amount' => $actual];
            $remaining -= $actual * $ask['price'];
        }

        return $result;
    }

    /**
     * Build a list of fillable bid levels constrained by the available base currency balance.
     * For each bid, computes how many units can be sold with the remaining balance.
     * When $baseBalance is null (pretend mode), all bid levels are returned as-is.
     *
     * @param  array<int, array{price: float, amount: float}>  $bids  Sorted descending by price.
     * @return array<int, array{price: float, amount: float}>
     */
    private function getMaxSellableBids(array $bids, ?float $baseBalance): array
    {
        if ($baseBalance === null) {
            return $bids;
        }

        $remaining = $baseBalance;
        $result = [];

        foreach ($bids as $bid) {
            $actual = min($remaining, $bid['amount']);

            if ($actual <= 0) {
                break;
            }

            $result[] = ['price' => $bid['price'], 'amount' => $actual];
            $remaining -= $actual;
        }

        return $result;
    }

    /**
     * Total buy cost (price × amount × (1 + fee)) for up to $maxAmount units across levels.
     *
     * @param  array<int, array{price: float, amount: float}>  $levels
     */
    private function computeBuyCost(float $txFee, float $maxAmount, array $levels): float
    {
        $total = 0.0;
        $remaining = $maxAmount;

        foreach ($levels as $level) {
            $actual = min($remaining, $level['amount']);
            $total += $level['price'] * $actual * (1 + $txFee);
            $remaining -= $actual;

            if ($remaining <= 0) {
                break;
            }
        }

        return $total;
    }

    /**
     * Total sell revenue (price × amount × (1 - fee)) for up to $maxAmount units across levels.
     *
     * @param  array<int, array{price: float, amount: float}>  $levels
     */
    private function computeSellRevenue(float $txFee, float $maxAmount, array $levels): float
    {
        $total = 0.0;
        $remaining = $maxAmount;

        foreach ($levels as $level) {
            $actual = min($remaining, $level['amount']);
            $total += $level['price'] * $actual * (1 - $txFee);
            $remaining -= $actual;

            if ($remaining <= 0) {
                break;
            }
        }

        return $total;
    }

    /**
     * Raw notional value (price × amount, no fees) for up to $maxAmount units across levels.
     * Used to derive average price.
     *
     * @param  array<int, array{price: float, amount: float}>  $levels
     */
    private function computeRawValue(float $maxAmount, array $levels): float
    {
        $total = 0.0;
        $remaining = $maxAmount;

        foreach ($levels as $level) {
            $actual = min($remaining, $level['amount']);
            $total += $level['price'] * $actual;
            $remaining -= $actual;

            if ($remaining <= 0) {
                break;
            }
        }

        return $total;
    }
}
