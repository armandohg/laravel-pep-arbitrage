<?php

namespace App\Console\Commands;

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use Illuminate\Console\Command;
use Throwable;

class ExchangesPingCommand extends Command
{
    protected $signature = 'exchanges:ping
        {--private : Also test authenticated (balance) endpoints}';

    protected $description = 'Verify connectivity to all exchanges — order books and optionally balances';

    public function __construct(
        private readonly Mexc $mexc,
        private readonly CoinEx $coinex,
        private readonly Kraken $kraken,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $private = (bool) $this->option('private');

        $this->info('Exchange connectivity check'.($private ? ' (including private endpoints)' : ''));
        $this->newLine();

        $allPassed = true;

        foreach ($this->checks($private) as [$label, $fn]) {
            try {
                $result = $fn();
                $this->line(sprintf('  <fg=green>✓</> %s', $label));

                if (is_string($result)) {
                    $this->line(sprintf('    <fg=gray>%s</>', $result));
                }
            } catch (Throwable $e) {
                $allPassed = false;
                $this->line(sprintf('  <fg=red>✗</> %s', $label));
                $this->line(sprintf('    <fg=red>%s</>', $e->getMessage()));
            }
        }

        $this->newLine();

        if ($allPassed) {
            $this->info('All checks passed.');
        } else {
            $this->error('Some checks failed — review the errors above.');
        }

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, array{0: string, 1: callable(): string|null}>
     */
    private function checks(bool $private): array
    {
        $checks = [
            [
                'MEXC   — order book (pep_usdt)',
                function (): string {
                    $book = $this->mexc->getOrderBook('pep_usdt');
                    $topBid = $book['bids'][0]['price'] ?? null;
                    $topAsk = $book['asks'][0]['price'] ?? null;

                    if ($topBid === null || $topAsk === null) {
                        throw new \RuntimeException('Empty order book returned');
                    }

                    return sprintf('bid %.8f  |  ask %.8f  (%d levels)', $topBid, $topAsk, count($book['bids']));
                },
            ],
            [
                'CoinEx — order book (pep_usdt)',
                function (): string {
                    $book = $this->coinex->getOrderBook('pep_usdt');
                    $topBid = $book['bids'][0]['price'] ?? null;
                    $topAsk = $book['asks'][0]['price'] ?? null;

                    if ($topBid === null || $topAsk === null) {
                        throw new \RuntimeException('Empty order book returned');
                    }

                    return sprintf('bid %.8f  |  ask %.8f  (%d levels)', $topBid, $topAsk, count($book['bids']));
                },
            ],
            [
                'Kraken — order book (pep_usd)',
                function (): string {
                    $book = $this->kraken->getOrderBook('pep_usd');
                    $topBid = $book['bids'][0]['price'] ?? null;
                    $topAsk = $book['asks'][0]['price'] ?? null;

                    if ($topBid === null || $topAsk === null) {
                        throw new \RuntimeException('Empty order book returned');
                    }

                    return sprintf('bid %.8f  |  ask %.8f  (%d levels)', $topBid, $topAsk, count($book['bids']));
                },
            ],
            [
                'Kraken — order book (usdt_usd)',
                function (): string {
                    $book = $this->kraken->getOrderBook('usdt_usd');
                    $topBid = $book['bids'][0]['price'] ?? null;
                    $topAsk = $book['asks'][0]['price'] ?? null;

                    if ($topBid === null || $topAsk === null) {
                        throw new \RuntimeException('Empty order book returned');
                    }

                    return sprintf('bid %.6f  |  ask %.6f', $topBid, $topAsk);
                },
            ],
        ];

        if ($private) {
            $checks[] = [
                'MEXC   — balances (private)',
                function (): string {
                    return $this->formatBalances($this->mexc->getBalances());
                },
            ];

            $checks[] = [
                'CoinEx — balances (private)',
                function (): string {
                    return $this->formatBalances($this->coinex->getBalances());
                },
            ];

            $checks[] = [
                'Kraken — balances (private)',
                function (): string {
                    return $this->formatBalances($this->kraken->getBalances());
                },
            ];
        }

        return $checks;
    }

    /**
     * @param  array<string, array{available: float}>  $balances
     */
    private function formatBalances(array $balances): string
    {
        if (empty($balances)) {
            return 'authenticated OK — no funds found';
        }

        $relevant = array_filter(
            $balances,
            fn (string $currency) => in_array($currency, ['USDT', 'USD', 'PEP'], true),
            ARRAY_FILTER_USE_KEY,
        );

        $display = ! empty($relevant) ? $relevant : array_slice($balances, 0, 3, true);

        $parts = [];

        foreach ($display as $currency => $data) {
            $parts[] = sprintf('%s: %.4f', $currency, $data['available']);
        }

        return implode('  |  ', $parts);
    }
}
