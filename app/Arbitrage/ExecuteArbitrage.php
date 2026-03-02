<?php

namespace App\Arbitrage;

use App\Arbitrage\ValueObjects\ExecutionResult;
use App\Arbitrage\ValueObjects\OpportunityData;
use App\Exchanges\Contracts\ExchangeInterface;
use App\Exchanges\ExchangeRegistry;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteArbitrage
{
    public function __construct(private readonly ExchangeRegistry $registry) {}

    /**
     * Execute buy and sell orders for the given opportunity.
     * For Kraken pairs, prices are converted to USD using $usdtUsdRate.
     */
    public function execute(OpportunityData $opportunity, float $usdtUsdRate): ExecutionResult
    {
        $orderType = config('arbitrage.order_type', 'limit');
        $buyExchange = $this->exchangeByName($opportunity->buyExchange);
        $sellExchange = $this->exchangeByName($opportunity->sellExchange);

        [$buySymbol, $buyPrice] = $this->resolveOrderParams($buyExchange, 'buy', $opportunity, $usdtUsdRate);
        [$sellSymbol, $sellPrice] = $this->resolveOrderParams($sellExchange, 'sell', $opportunity, $usdtUsdRate);

        // Place buy order first — if it fails, nothing happened yet.
        try {
            $buyResult = $buyExchange->placeOrder(
                $buySymbol,
                'buy',
                $opportunity->amount,
                $orderType,
                $orderType === 'limit' ? $buyPrice : null,
            );
        } catch (Throwable $e) {
            return new ExecutionResult(
                success: false,
                buyOrderId: null,
                sellOrderId: null,
                failedSide: 'buy',
                error: $e->getMessage(),
            );
        }

        $buyOrderId = $buyResult['orderId'];

        // Place sell order — if it fails we have an open buy position.
        try {
            $sellResult = $sellExchange->placeOrder(
                $sellSymbol,
                'sell',
                $opportunity->amount,
                $orderType,
                $orderType === 'limit' ? $sellPrice : null,
            );
        } catch (Throwable $e) {
            Log::critical('Arbitrage sell order failed after buy was placed — manual intervention required.', [
                'buy_exchange' => $opportunity->buyExchange,
                'sell_exchange' => $opportunity->sellExchange,
                'buy_order_id' => $buyOrderId,
                'amount' => $opportunity->amount,
                'error' => $e->getMessage(),
            ]);

            return new ExecutionResult(
                success: false,
                buyOrderId: $buyOrderId,
                sellOrderId: null,
                failedSide: 'sell',
                error: $e->getMessage(),
            );
        }

        return new ExecutionResult(
            success: true,
            buyOrderId: $buyOrderId,
            sellOrderId: $sellResult['orderId'],
            failedSide: null,
            error: null,
        );
    }

    /**
     * Resolve the trading symbol and price for an order.
     * Kraken operates in USD, so prices are converted from USDT using the rate.
     *
     * @return array{string, float}
     */
    private function resolveOrderParams(
        ExchangeInterface $exchange,
        string $side,
        OpportunityData $opportunity,
        float $usdtUsdRate,
    ): array {
        $isKraken = $exchange->getName() === 'Kraken';

        if ($isKraken) {
            if ($side === 'buy') {
                $usdtPrice = max(array_column($opportunity->buyLevels, 'price'));
                $price = $usdtPrice * $usdtUsdRate;
            } else {
                $usdtPrice = min(array_column($opportunity->sellLevels, 'price'));
                $price = $usdtPrice * $usdtUsdRate;
            }

            return ['pep_usdt', $price];
        }

        if ($side === 'buy') {
            $price = max(array_column($opportunity->buyLevels, 'price'));
        } else {
            $price = min(array_column($opportunity->sellLevels, 'price'));
        }

        return ['pep_usdt', $price];
    }

    private function exchangeByName(string $name): ExchangeInterface
    {
        return $this->registry->get($name);
    }
}
