<?php

namespace App\Console\Commands;

use App\Arbitrage\DetectOpportunity;
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
        {--interval=5 : Seconds between polls}
        {--min-profit=0.003 : Minimum profit ratio to record (e.g. 0.003 = 0.3%)}
        {--once : Run a single poll and exit}';

    protected $description = 'Monitor PEP order books across exchanges and detect arbitrage opportunities';

    public function __construct(
        private readonly Mexc $mexc,
        private readonly CoinEx $coinex,
        private readonly Kraken $kraken,
        private readonly DetectOpportunity $detector,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $interval = (int) $this->option('interval');
        $minProfit = (float) $this->option('min-profit');
        $once = (bool) $this->option('once');

        $this->info(sprintf(
            'PEP arbitrage monitor | interval: %ds | min-profit: %.2f%%',
            $interval,
            $minProfit * 100,
        ));

        $shouldStop = false;

        $this->trap([SIGTERM, SIGINT], function () use (&$shouldStop) {
            $shouldStop = true;
            $this->newLine();
            $this->info('Shutting down...');
        });

        do {
            $this->poll($minProfit);

            if (! $once && ! $shouldStop) {
                sleep($interval);
            }
        } while (! $once && ! $shouldStop);

        return self::SUCCESS;
    }

    protected function poll(float $minProfit): void
    {
        try {
            $mexcBook = $this->mexc->getOrderBook('pep_usdt');
            $coinexBook = $this->coinex->getOrderBook('pep_usdt');
            $krakenPepBook = $this->kraken->getOrderBook('pep_usd');
            $krakenUsdtBook = $this->kraken->getOrderBook('usdt_usd');

            $usdtUsdRate = $this->usdtUsdRate($krakenUsdtBook);
            $krakenNormalized = DetectOpportunity::normalizeToUsdt($krakenPepBook, $usdtUsdRate);

            $pairs = [
                [$this->mexc, $mexcBook, $this->coinex, $coinexBook],
                [$this->mexc, $mexcBook, $this->kraken, $krakenNormalized],
                [$this->coinex, $coinexBook, $this->kraken, $krakenNormalized],
            ];

            $found = 0;

            foreach ($pairs as [$exchangeA, $bookA, $exchangeB, $bookB]) {
                $opportunity = $this->detector->between($exchangeA, $bookA, $exchangeB, $bookB, $minProfit);

                if ($opportunity !== null) {
                    ArbitrageOpportunity::fromOpportunityData($opportunity);
                    $found++;

                    $this->line(sprintf(
                        '[%s] OPPORTUNITY %s → %s | profit: %.4f%% (%s) | amount: %.0f PEP',
                        now()->format('H:i:s'),
                        $opportunity->buyExchange,
                        $opportunity->sellExchange,
                        $opportunity->profitRatio * 100,
                        $opportunity->profitLevel,
                        $opportunity->amount,
                    ));
                }
            }

            if ($found === 0) {
                $this->line(sprintf(
                    '[%s] No opportunities above %.2f%%',
                    now()->format('H:i:s'),
                    $minProfit * 100,
                ));
            }
        } catch (Throwable $e) {
            $this->error(sprintf('[%s] Poll error: %s', now()->format('H:i:s'), $e->getMessage()));
            Log::warning('arbitrage:find poll error', ['message' => $e->getMessage()]);
        }
    }

    /**
     * @param  array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}  $usdtUsdBook
     */
    private function usdtUsdRate(array $usdtUsdBook): float
    {
        $bestBid = $usdtUsdBook['bids'][0]['price'] ?? null;
        $bestAsk = $usdtUsdBook['asks'][0]['price'] ?? null;

        if ($bestBid === null || $bestAsk === null) {
            throw new RuntimeException('Kraken USDT/USD order book has no data');
        }

        return ($bestBid + $bestAsk) / 2;
    }
}
