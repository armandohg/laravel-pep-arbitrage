<?php

namespace App\Console\Commands;

use App\Arbitrage\DetectOpportunity;
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
        {--interval=5 : Seconds between polls}
        {--min-profit=0.003 : Minimum profit ratio to record (e.g. 0.003 = 0.3%)}
        {--once : Run a single poll and exit}
        {--pretend : Ignore balances and detect the maximum theoretical opportunity (simulation only)}';

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
        $pretend = (bool) $this->option('pretend');

        $this->info(sprintf(
            'PEP arbitrage monitor | interval: %ds | min-profit: %.2f%%%s',
            $interval,
            $minProfit * 100,
            $pretend ? ' | pretend mode (balances ignored)' : '',
        ));

        $shouldStop = false;

        $this->trap([SIGTERM, SIGINT], function () use (&$shouldStop) {
            $shouldStop = true;
            $this->newLine();
            $this->info('Shutting down...');
        });

        do {
            $this->poll($minProfit, $pretend);

            if (! $once && ! $shouldStop) {
                sleep($interval);
            }
        } while (! $once && ! $shouldStop);

        return self::SUCCESS;
    }

    protected function poll(float $minProfit, bool $pretend): void
    {
        try {
            $mexcBook = $this->mexc->getOrderBook('pep_usdt');
            $coinexBook = $this->coinex->getOrderBook('pep_usdt');
            $krakenPepBookRaw = $this->kraken->getOrderBook('pep_usd');
            $krakenUsdtBook = $this->kraken->getOrderBook('usdt_usd');

            $usdtUsdRate = $this->usdtUsdRate($krakenUsdtBook);
            $krakenNormalized = DetectOpportunity::normalizeToUsdt($krakenPepBookRaw, $usdtUsdRate);

            // Quote balances in USDT-equivalent (Kraken USD is converted).
            // Base balance is PEP on each exchange.
            if ($pretend) {
                $quoteBalances = [];
                $baseBalances = [];
            } else {
                $balances = $this->fetchBalances();
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
            }

            $pairs = [
                [$this->mexc, $mexcBook, $this->coinex, $coinexBook],
                [$this->mexc, $mexcBook, $this->kraken, $krakenNormalized],
                [$this->coinex, $coinexBook, $this->kraken, $krakenNormalized],
            ];

            $found = 0;

            foreach ($pairs as [$exchangeA, $bookA, $exchangeB, $bookB]) {
                $opportunity = $this->detector->between(
                    $exchangeA, $bookA,
                    $exchangeB, $bookB,
                    $minProfit,
                    $pretend ? null : $quoteBalances[$exchangeA->getName()],
                    $pretend ? null : $baseBalances[$exchangeA->getName()],
                    $pretend ? null : $quoteBalances[$exchangeB->getName()],
                    $pretend ? null : $baseBalances[$exchangeB->getName()],
                );

                if ($opportunity !== null) {
                    ArbitrageOpportunity::fromOpportunityData($opportunity);
                    $found++;
                    $this->outputOpportunity($opportunity);
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
     * Fetch balances from all three exchanges, keyed by exchange name.
     *
     * @return array<string, array<string, array{available: float}>>
     */
    private function fetchBalances(): array
    {
        return [
            $this->mexc->getName() => $this->mexc->getBalances(),
            $this->coinex->getName() => $this->coinex->getBalances(),
            $this->kraken->getName() => $this->kraken->getBalances(),
        ];
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
