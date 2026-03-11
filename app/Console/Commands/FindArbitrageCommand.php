<?php

namespace App\Console\Commands;

use App\Arbitrage\DetectOpportunity;
use App\Arbitrage\ExecuteArbitrage;
use App\Arbitrage\ValueObjects\OpportunityData;
use App\Exchanges\Contracts\ExchangeInterface;
use App\Exchanges\ExchangeRegistry;
use App\Models\ArbitrageOpportunity;
use App\Models\ArbitrageSettings;
use App\Rebalance\RebalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FindArbitrageCommand extends Command
{
    protected $signature = 'arbitrage:find
        {--once : Run a single discovery poll and exit}
        {--pretend : Ignore balances and detect the maximum theoretical opportunity (simulation only)}
        {--exit-after-execution : Stop the process after the first successful arbitrage execution}';

    protected $description = 'Monitor PEP order books across exchanges and detect arbitrage opportunities';

    private float $usdtUsdRate = 1.0;

    public function __construct(
        private readonly ExchangeRegistry $registry,
        private readonly DetectOpportunity $detector,
        private readonly ExecuteArbitrage $executeArbitrage,
        private readonly RebalanceService $rebalanceService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $once = (bool) $this->option('once');
        $pretend = (bool) $this->option('pretend');

        $this->info('PEP arbitrage monitor | settings loaded from DB'.($pretend ? ' | pretend mode' : ''));

        $shouldStop = false;

        $this->trap([SIGTERM, SIGINT], function () use (&$shouldStop) {
            $shouldStop = true;
            $this->newLine();
            $this->info('Shutting down...');
        });

        do {
            // Re-read settings from DB once per discovery cycle so changes take effect without restart.
            $settings = ArbitrageSettings::current();

            // Phase 1 — Discovery: poll all pairs and find the best opportunity.
            $best = $this->discoverBestOpportunity($settings->min_profit_ratio, $settings->min_amount, $pretend);

            if ($best !== null) {
                // Phase 2 — Sustain: monitor only the winning pair until confirmed or lost.
                $confirmed = $this->monitorUntilConfirmed(
                    $best,
                    $settings->min_profit_ratio,
                    $settings->min_amount,
                    $settings->sustain_duration,
                    $settings->sustain_interval,
                    $settings->stability,
                    $pretend,
                    $shouldStop,
                );

                if ($confirmed) {
                    $this->executeBestOpportunity($best, $pretend, $settings->execute_orders);

                    if ($this->option('exit-after-execution')) {
                        break;
                    }
                }
            }

            if (! $once && ! $shouldStop) {
                sleep($settings->discovery_interval);
            }
        } while (! $once && ! $shouldStop);

        return self::SUCCESS;
    }

    /**
     * Phase 1 — Poll all exchange pairs and return the best opportunity found, or null.
     */
    protected function discoverBestOpportunity(float $minProfit, float $minAmount, bool $pretend): ?OpportunityData
    {
        $this->line(sprintf('[%s] <fg=cyan>[DISCOVERY]</> Polling all exchange pairs...', now()->format('H:i:s')));

        try {
            [$books, $usdtUsdRate] = $this->fetchAllBooks();
            $this->usdtUsdRate = $usdtUsdRate;
            [$quoteBalances, $baseBalances] = $this->resolveBalances($pretend, $books['usdtUsdRate']);

            $mexc = $this->registry->get('Mexc');
            $coinex = $this->registry->get('CoinEx');
            $kraken = $this->registry->get('Kraken');

            $pairs = [
                [$mexc, $books['mexc'], $coinex, $books['coinex']],
                [$mexc, $books['mexc'], $kraken, $books['krakenNormalized']],
                [$coinex, $books['coinex'], $kraken, $books['krakenNormalized']],
            ];

            /** @var OpportunityData|null $best */
            $best = null;

            foreach ($pairs as [$exchangeA, $bookA, $exchangeB, $bookB]) {
                $opportunity = $this->detector->between(
                    $exchangeA, $bookA,
                    $exchangeB, $bookB,
                    $minProfit,
                    $pretend ? null : $quoteBalances[$exchangeA->getName()],
                    $pretend ? null : $baseBalances[$exchangeA->getName()],
                    $pretend ? null : $quoteBalances[$exchangeB->getName()],
                    $pretend ? null : $baseBalances[$exchangeB->getName()],
                    minAmount: $minAmount,
                );

                if ($opportunity !== null) {
                    ArbitrageOpportunity::fromOpportunityData($opportunity);
                    $this->outputOpportunity($opportunity);

                    if ($best === null || $opportunity->profitRatio > $best->profitRatio) {
                        $best = $opportunity;
                    }
                }
            }

            if ($best === null) {
                $this->line(sprintf('[%s] No opportunities above %.2f%%', now()->format('H:i:s'), $minProfit * 100));
            } else {
                $this->line(sprintf(
                    '[%s] <fg=yellow>Best opportunity: %s → %s @ +%.4f%% — entering sustain phase.</>',
                    now()->format('H:i:s'),
                    $best->buyExchange,
                    $best->sellExchange,
                    $best->profitRatio * 100,
                ));
            }

            return $best;
        } catch (Throwable $e) {
            $this->error(sprintf('[%s] Discovery error: %s', now()->format('H:i:s'), $e->getMessage()));
            Log::warning('arbitrage:find discovery error', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Phase 2 — Monitor only the winning exchange pair.
     * Returns true if the opportunity is confirmed after the sustain duration, false if it is lost.
     */
    protected function monitorUntilConfirmed(
        OpportunityData $candidate,
        float $minProfit,
        float $minAmount,
        int $sustainDuration,
        int $sustainInterval,
        float $stability,
        bool $pretend,
        bool &$shouldStop,
    ): bool {
        $buyExchange = $this->exchangeByName($candidate->buyExchange);
        $sellExchange = $this->exchangeByName($candidate->sellExchange);
        $sustainedSince = now()->timestamp;
        $initialProfitPct = $candidate->profitRatio * 100;

        $this->line(sprintf(
            '[%s] <fg=cyan>[SUSTAIN]</> Monitoring %s → %s for %ds (check every %ds)...',
            now()->format('H:i:s'),
            $candidate->buyExchange,
            $candidate->sellExchange,
            $sustainDuration,
            $sustainInterval,
        ));

        while (! $shouldStop) {
            $elapsed = now()->timestamp - $sustainedSince;

            if ($elapsed >= $sustainDuration) {
                return true;
            }

            sleep($sustainInterval);

            try {
                [$books, $usdtUsdRate] = $this->fetchBooksForPair($buyExchange, $sellExchange);
                [$quoteBalances, $baseBalances] = $this->resolveBalances($pretend, $usdtUsdRate);

                $opportunity = $this->detector->between(
                    $buyExchange, $books['buy'],
                    $sellExchange, $books['sell'],
                    $minProfit,
                    $pretend ? null : $quoteBalances[$buyExchange->getName()],
                    $pretend ? null : $baseBalances[$buyExchange->getName()],
                    $pretend ? null : $quoteBalances[$sellExchange->getName()],
                    $pretend ? null : $baseBalances[$sellExchange->getName()],
                    minAmount: $minAmount,
                );

                if ($opportunity === null) {
                    $this->line(sprintf(
                        '[%s] <fg=red>[SUSTAIN]</> Opportunity lost after %ds. Returning to discovery.',
                        now()->format('H:i:s'),
                        $elapsed,
                    ));

                    return false;
                }

                $currentProfitPct = $opportunity->profitRatio * 100;
                $minAllowed = $initialProfitPct - $stability;
                $maxAllowed = $initialProfitPct + $stability;

                if ($currentProfitPct < $minAllowed || $currentProfitPct > $maxAllowed) {
                    $this->line(sprintf(
                        '[%s] <fg=red>[SUSTAIN]</> Profit drifted too much (%.4f%% → %.4f%%, tolerance ±%.1f%%). Returning to discovery.',
                        now()->format('H:i:s'),
                        $initialProfitPct,
                        $currentProfitPct,
                        $stability,
                    ));

                    return false;
                }

                $remaining = $sustainDuration - $elapsed;
                $this->line(sprintf(
                    '[%s] <fg=cyan>[SUSTAIN]</> Holding — profit: %.4f%% | %ds remaining.',
                    now()->format('H:i:s'),
                    $currentProfitPct,
                    $remaining,
                ));
            } catch (Throwable $e) {
                $this->error(sprintf('[%s] Sustain check error: %s', now()->format('H:i:s'), $e->getMessage()));
                Log::warning('arbitrage:find sustain error', ['message' => $e->getMessage()]);

                return false;
            }
        }

        return false;
    }

    /**
     * Execute the confirmed opportunity.
     */
    protected function executeBestOpportunity(OpportunityData $opportunity, bool $pretend, bool $executeOrders): void
    {
        $this->info(sprintf(
            '[%s] >>> EXECUTION CRITERIA MET: %s → %s | profit: +%.4f%% | %s USDT <<<',
            now()->format('H:i:s'),
            $opportunity->buyExchange,
            $opportunity->sellExchange,
            $opportunity->profitRatio * 100,
            number_format($opportunity->profit, 4),
        ));

        if ($pretend) {
            $this->warn('[pretend] Would execute trade — skipping real orders.');

            return;
        }

        if (! $executeOrders) {
            $this->warn('Enable "Execute Real Orders" in settings to place real orders.');

            return;
        }

        $model = ArbitrageOpportunity::where('buy_exchange', $opportunity->buyExchange)
            ->where('sell_exchange', $opportunity->sellExchange)
            ->whereNull('execution_status')
            ->latest()
            ->first();

        $result = $this->executeArbitrage->execute($opportunity, $this->usdtUsdRate);

        $buyPrice = ! empty($opportunity->buyLevels) ? max(array_column($opportunity->buyLevels, 'price')) : $opportunity->avgBuyPrice;
        $sellPrice = ! empty($opportunity->sellLevels) ? min(array_column($opportunity->sellLevels, 'price')) : $opportunity->avgSellPrice;

        $model?->recordExecution($result, $opportunity->amount, $buyPrice, $sellPrice);

        Cache::forget('exchange_balances_'.strtolower($opportunity->buyExchange));
        Cache::forget('exchange_balances_'.strtolower($opportunity->sellExchange));

        if ($result->success) {
            $this->info(sprintf(
                '[%s] Orders placed — buy: %s | sell: %s',
                now()->format('H:i:s'),
                $result->buyOrderId,
                $result->sellOrderId,
            ));

            // Automatically rebalance if needed after successful arbitrage
            try {
                if ($this->rebalanceService->rebalanceIfNeeded()) {
                    $this->info('['.now()->format('H:i:s').'] Automatic rebalance executed to restore liquidity.');
                }
            } catch (Throwable $e) {
                Log::warning('Automatic rebalance after arbitrage failed', ['error' => $e->getMessage()]);
                $this->warn('['.now()->format('H:i:s').'] Automatic rebalance failed (will try on next cycle): '.$e->getMessage());
            }
        } else {
            $this->error(sprintf(
                '[%s] Execution failed (side: %s): %s',
                now()->format('H:i:s'),
                $result->failedSide ?? 'unknown',
                $result->error,
            ));
        }
    }

    private function outputOpportunity(OpportunityData $opportunity): void
    {
        $levelColor = match ($opportunity->profitLevel) {
            'Extreme' => 'red',
            'VeryHigh' => 'yellow',
            'High' => 'green',
            'Medium' => 'cyan',
            default => 'white',
        };

        $this->line(sprintf(
            '[%s] <fg=white;options=bold>OPPORTUNITY</> <fg=cyan>%s → %s</> [<fg=%s>%s</>]',
            now()->format('H:i:s'),
            $opportunity->buyExchange,
            $opportunity->sellExchange,
            $levelColor,
            $opportunity->profitLevel,
        ));

        $this->line(sprintf(
            '           ├ Amount  : %s PEP',
            number_format($opportunity->amount, 0),
        ));

        $multiLevel = count($opportunity->buyLevels) > 1 || count($opportunity->sellLevels) > 1;

        // BUY side
        $this->line(sprintf(
            '           ├ <options=bold>BUY</>  on %-7s : avg %.8f USDT/PEP  |  cost    %s USDT',
            $opportunity->buyExchange,
            $opportunity->avgBuyPrice,
            number_format($opportunity->totalBuyCost, 4),
        ));

        if ($multiLevel) {
            foreach ($opportunity->buyLevels as $i => $level) {
                $prefix = $i === array_key_last($opportunity->buyLevels) ? '│              └' : '│              ├';
                $this->line(sprintf(
                    '           %s level %d : %.8f USDT/PEP × %s PEP',
                    $prefix,
                    $i + 1,
                    $level['price'],
                    number_format($level['amount'], 0),
                ));
            }
        }

        // SELL side
        $this->line(sprintf(
            '           ├ <options=bold>SELL</> on %-7s : avg %.8f USDT/PEP  |  revenue %s USDT',
            $opportunity->sellExchange,
            $opportunity->avgSellPrice,
            number_format($opportunity->totalSellRevenue, 4),
        ));

        if ($multiLevel) {
            foreach ($opportunity->sellLevels as $i => $level) {
                $prefix = $i === array_key_last($opportunity->sellLevels) ? '│              └' : '│              ├';
                $this->line(sprintf(
                    '           %s level %d : %.8f USDT/PEP × %s PEP',
                    $prefix,
                    $i + 1,
                    $level['price'],
                    number_format($level['amount'], 0),
                ));
            }
        }

        $this->line(sprintf(
            '           └ <fg=green>Profit      : +%s USDT  (+%.4f%%)</>',
            number_format($opportunity->profit, 4),
            $opportunity->profitRatio * 100,
        ));
    }

    /**
     * Fetch order books from all exchanges for the discovery phase.
     * Returns a books map and the resolved USDT/USD rate.
     *
     * @return array{array{mexc: array, coinex: array, krakenNormalized: array, usdtUsdRate: float}, float}
     */
    private function fetchAllBooks(): array
    {
        $mexcBook = $this->registry->get('Mexc')->getOrderBook('pep_usdt');
        $coinexBook = $this->registry->get('CoinEx')->getOrderBook('pep_usdt');
        $krakenPepBookRaw = $this->registry->get('Kraken')->getOrderBook('pep_usd');
        $krakenUsdtBook = $this->registry->get('Kraken')->getOrderBook('usdt_usd');

        $usdtUsdRate = $this->extractUsdtUsdRate($krakenUsdtBook);
        $krakenNormalized = DetectOpportunity::normalizeToUsdt($krakenPepBookRaw, $usdtUsdRate);

        return [
            [
                'mexc' => $mexcBook,
                'coinex' => $coinexBook,
                'krakenNormalized' => $krakenNormalized,
                'usdtUsdRate' => $usdtUsdRate,
            ],
            $usdtUsdRate,
        ];
    }

    /**
     * Fetch order books only for the two exchanges in the winning pair (sustain phase).
     * Returns a books map and the resolved USDT/USD rate.
     *
     * @return array{array{buy: array, sell: array}, float}
     */
    private function fetchBooksForPair(
        \App\Exchanges\Contracts\ExchangeInterface $buyExchange,
        \App\Exchanges\Contracts\ExchangeInterface $sellExchange,
    ): array {
        $needsKraken = $buyExchange->getName() === 'Kraken' || $sellExchange->getName() === 'Kraken';

        $usdtUsdRate = 1.0;

        if ($needsKraken) {
            $krakenUsdtBook = $this->registry->get('Kraken')->getOrderBook('usdt_usd');
            $usdtUsdRate = $this->extractUsdtUsdRate($krakenUsdtBook);
        }

        $buyBook = $this->fetchNormalizedBook($buyExchange, $usdtUsdRate);
        $sellBook = $this->fetchNormalizedBook($sellExchange, $usdtUsdRate);

        return [['buy' => $buyBook, 'sell' => $sellBook], $usdtUsdRate];
    }

    /**
     * @param  array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}  $usdtUsdBook
     */
    private function extractUsdtUsdRate(array $usdtUsdBook): float
    {
        $bestBid = $usdtUsdBook['bids'][0]['price'] ?? null;
        $bestAsk = $usdtUsdBook['asks'][0]['price'] ?? null;

        if ($bestBid === null || $bestAsk === null) {
            throw new RuntimeException('Kraken USDT/USD order book has no data');
        }

        return ($bestBid + $bestAsk) / 2;
    }

    /**
     * Fetch and normalize an order book for a given exchange.
     * Kraken's PEP book is normalized to USDT using the given rate.
     *
     * @return array{bids: array, asks: array}
     */
    private function fetchNormalizedBook(
        \App\Exchanges\Contracts\ExchangeInterface $exchange,
        float $usdtUsdRate,
    ): array {
        if ($exchange->getName() === 'Kraken') {
            $raw = $exchange->getOrderBook('pep_usd');

            return DetectOpportunity::normalizeToUsdt($raw, $usdtUsdRate);
        }

        return $exchange->getOrderBook('pep_usdt');
    }

    /**
     * Resolve quote and base balances for all exchanges, or return empty arrays in pretend mode.
     *
     * @return array{array<string, float>, array<string, float>}
     */
    private function resolveBalances(bool $pretend, float $usdtUsdRate): array
    {
        if ($pretend) {
            return [[], []];
        }

        $balances = [];

        foreach ($this->registry->all() as $exchange) {
            $balances[$exchange->getName()] = $exchange->getBalances();
        }

        $quoteBalances = [
            'Mexc' => $balances['Mexc']['USDT']['available'] ?? 0.0,
            'CoinEx' => $balances['CoinEx']['USDT']['available'] ?? 0.0,
            'Kraken' => ($balances['Kraken']['USD']['available'] ?? 0.0) / $usdtUsdRate,
        ];

        $baseBalances = [
            'Mexc' => $balances['Mexc']['PEP']['available'] ?? 0.0,
            'CoinEx' => $balances['CoinEx']['PEP']['available'] ?? 0.0,
            'Kraken' => $balances['Kraken']['PEP']['available'] ?? 0.0,
        ];

        return [$quoteBalances, $baseBalances];
    }

    private function exchangeByName(string $name): ExchangeInterface
    {
        return $this->registry->get($name);
    }
}
