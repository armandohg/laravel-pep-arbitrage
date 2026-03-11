<?php

namespace App\Console\Commands;

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use Illuminate\Console\Command;

class ExchangesDiscoverPairsCommand extends Command
{
    protected $signature = 'exchanges:discover-pairs
        {--min-volume=50000 : Minimum 24h volume (USDT) per exchange to include a pair}
        {--top=30 : Number of top pairs to display, ranked by combined volume}';

    protected $description = 'Discover trading pairs available on all 3 exchanges, ranked by 24h volume';

    public function __construct(
        private readonly Mexc $mexc,
        private readonly CoinEx $coinex,
        private readonly Kraken $kraken,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $minVolume = (float) $this->option('min-volume');
        $top = (int) $this->option('top');

        $this->info('Fetching markets from all exchanges...');

        $mexcMarkets = $this->fetchMarkets('MEXC', fn () => $this->mexc->getAvailableMarkets());
        $coinexMarkets = $this->fetchMarkets('CoinEx', fn () => $this->coinex->getAvailableMarkets());
        $krakenMarkets = $this->fetchMarkets('Kraken', fn () => $this->kraken->getAvailableMarkets());

        if (empty($mexcMarkets) || empty($coinexMarkets) || empty($krakenMarkets)) {
            $this->error('Failed to fetch markets from one or more exchanges.');

            return self::FAILURE;
        }

        $mexcByBase = collect($mexcMarkets)->keyBy('base');
        $coinexByBase = collect($coinexMarkets)->keyBy('base');
        $krakenByBase = collect($krakenMarkets)->keyBy('base');

        $this->newLine();
        $this->line(sprintf('  MEXC:   <comment>%s</comment> USDT markets', number_format($mexcByBase->count())));
        $this->line(sprintf('  CoinEx: <comment>%s</comment> USDT markets', number_format($coinexByBase->count())));
        $this->line(sprintf('  Kraken: <comment>%s</comment> USD markets', number_format($krakenByBase->count())));

        $commonBases = $mexcByBase->keys()
            ->intersect($coinexByBase->keys())
            ->intersect($krakenByBase->keys());

        $this->newLine();
        $this->line(sprintf('Intersection: <comment>%d pairs</comment> available on all 3 exchanges', $commonBases->count()));

        $pairs = $commonBases
            ->map(function (string $base) use ($mexcByBase, $coinexByBase, $krakenByBase): array {
                $mexc = $mexcByBase[$base];
                $coinex = $coinexByBase[$base];
                $kraken = $krakenByBase[$base];

                return [
                    'base' => $base,
                    'mexc_volume' => $mexc['quote_volume_24h'],
                    'coinex_volume' => $coinex['quote_volume_24h'],
                    'kraken_volume' => $kraken['quote_volume_24h'],
                    'min_exchange_volume' => min($mexc['quote_volume_24h'], $coinex['quote_volume_24h'], $kraken['quote_volume_24h']),
                    'total_volume' => $mexc['quote_volume_24h'] + $coinex['quote_volume_24h'] + $kraken['quote_volume_24h'],
                    'avg_change' => ($mexc['price_change_pct'] + $coinex['price_change_pct'] + $kraken['price_change_pct']) / 3,
                ];
            })
            ->filter(fn (array $pair): bool => $pair['min_exchange_volume'] >= $minVolume)
            ->sortByDesc('total_volume')
            ->take($top);

        $this->line(sprintf(
            'Filtered (min $%s/exchange): <comment>%d pairs</comment> | Showing top %d by combined volume',
            number_format($minVolume),
            $pairs->count(),
            min($top, $pairs->count()),
        ));

        $this->newLine();

        $rows = $pairs->map(fn (array $pair): array => [
            $pair['base'].'/USDT',
            $this->formatVolume($pair['mexc_volume']),
            $this->formatVolume($pair['coinex_volume']),
            $this->formatVolume($pair['kraken_volume']),
            $this->formatVolume($pair['total_volume']),
            sprintf('%+.1f%%', $pair['avg_change']),
        ])->values()->toArray();

        $this->table(
            ['Pair', 'MEXC 24h', 'CoinEx 24h', 'Kraken 24h (USD)', 'Combined', 'Avg Δ 24h'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{symbol: string, base: string, quote_volume_24h: float, price_change_pct: float, last_price: float}>
     */
    private function fetchMarkets(string $name, callable $fn): array
    {
        try {
            $markets = $fn();
            $this->line("  ✓ {$name}: ".count($markets).' markets loaded');

            return $markets;
        } catch (\Throwable $e) {
            $this->error("  ✗ {$name}: ".$e->getMessage());

            return [];
        }
    }

    private function formatVolume(float $amount): string
    {
        if ($amount >= 1_000_000_000) {
            return sprintf('$%.2fB', $amount / 1_000_000_000);
        }

        if ($amount >= 1_000_000) {
            return sprintf('$%.1fM', $amount / 1_000_000);
        }

        if ($amount >= 1_000) {
            return sprintf('$%.1fK', $amount / 1_000);
        }

        return sprintf('$%.0f', $amount);
    }
}
