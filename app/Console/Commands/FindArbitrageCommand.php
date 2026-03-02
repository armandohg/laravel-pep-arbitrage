<?php

namespace App\Console\Commands;

use App\Arbitrage\DetectOpportunity;
use App\Arbitrage\ExecuteArbitrage;
use App\Arbitrage\ValueObjects\OpportunityData;
use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use App\Models\ArbitrageOpportunity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FindArbitrageCommand extends Command
{
    protected $signature = 'arbitrage:find
        {--interval=5 : Seconds between discovery polls}
        {--min-profit=0.003 : Minimum profit ratio to record (e.g. 0.003 = 0.3%)}
        {--sustain=10 : Seconds the winning pair must hold before executing}
        {--sustain-interval=2 : Seconds between checks during the sustain phase}
        {--stability=0.5 : Profit % drift tolerance during sustain (e.g. 0.5 = ±0.5%)}
        {--once : Run a single discovery poll and exit}
        {--pretend : Ignore balances and detect the maximum theoretical opportunity (simulation only)}
        {--execute : Execute real orders when opportunity is confirmed}
        {--min-amount=0 : Minimum trade amount in USD/USDT to consider an opportunity (e.g. 5 = $5 minimum)}';

    protected $description = 'Monitor PEP order books across exchanges and detect arbitrage opportunities';

    private float $usdtUsdRate = 1.0;

    public function __construct(
        private readonly Mexc $mexc,
        private readonly CoinEx $coinex,
        private readonly Kraken $kraken,
        private readonly DetectOpportunity $detector,
        private readonly ExecuteArbitrage $executeArbitrage,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $interval = (int) $this->option('interval');
        $minProfit = (float) $this->option('min-profit');
        $minAmount = (float) $this->option('min-amount');
        $sustainDuration = (int) $this->option('sustain');
        $sustainInterval = (int) $this->option('sustain-interval');
        $stability = (float) $this->option('stability');
        $once = (bool) $this->option('once');
        $pretend = (bool) $this->option('pretend');

        $this->info(sprintf(
            'PEP arbitrage monitor | discovery: %ds | sustain: %ds (check every %ds, tolerance ±%.1f%%) | min-profit: %.2f%% | min-amount: $%.2f%s',
            $interval,
            $sustainDuration,
            $sustainInterval,
            $stability,
            $minProfit * 100,
            $minAmount,
            $pretend ? ' | pretend mode' : '',
        ));

        $shouldStop = false;

        $this->trap([SIGTERM, SIGINT], function () use (&$shouldStop) {
            $shouldStop = true;
            $this->newLine();
            $this->info('Shutting down...');
        });

        do {
            // Phase 1 — Discovery: poll all pairs and find the best opportunity.
            $best = $this->discoverBestOpportunity($minProfit, $minAmount, $pretend);

            if ($best !== null) {
                // Phase 2 — Sustain: monitor only the winning pair until confirmed or lost.
                $confirmed = $this->monitorUntilConfirmed($best, $minProfit, $minAmount, $sustainDuration, $sustainInterval, $stability, $pretend, $shouldStop);

                if ($confirmed) {
                    $this->executeBestOpportunity($best, $pretend);
                }
            }

            if (! $once && ! $shouldStop) {
                sleep($interval);
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

            $pairs = [
                [$this->mexc, $books['mexc'], $this->coinex, $books['coinex']],
                [$this->mexc, $books['mexc'], $this->kraken, $books['krakenNormalized']],
                [$this->coinex, $books['coinex'], $this->kraken, $books['krakenNormalized']],
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
    protected function executeBestOpportunity(OpportunityData $opportunity, bool $pretend): void
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

        if (! $this->option('execute')) {
            $this->warn('Use --execute to place real orders.');

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

        if ($result->success) {
            $this->info(sprintf(
                '[%s] Orders placed — buy: %s | sell: %s',
                now()->format('H:i:s'),
                $result->buyOrderId,
                $result->sellOrderId,
            ));
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
        $mexcBook = $this->mexc->getOrderBook('pep_usdt');
        $coinexBook = $this->coinex->getOrderBook('pep_usdt');
        $krakenPepBookRaw = $this->kraken->getOrderBook('pep_usd');
        $krakenUsdtBook = $this->kraken->getOrderBook('usdt_usd');

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
            $krakenUsdtBook = $this->kraken->getOrderBook('usdt_usd');
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

        $balances = [
            $this->mexc->getName() => $this->mexc->getBalances(),
            $this->coinex->getName() => $this->coinex->getBalances(),
            $this->kraken->getName() => $this->kraken->getBalances(),
        ];

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

    /**
     * Resolve an exchange instance by its name.
     */
    private function exchangeByName(string $name): \App\Exchanges\Contracts\ExchangeInterface
    {
        return match ($name) {
            'Mexc' => $this->mexc,
            'CoinEx' => $this->coinex,
            'Kraken' => $this->kraken,
            default => throw new RuntimeException("Unknown exchange: {$name}"),
        };
    }
}
