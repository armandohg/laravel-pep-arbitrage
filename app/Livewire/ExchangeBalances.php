<?php

namespace App\Livewire;

use App\Exchanges\ExchangeRegistry;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class ExchangeBalances extends Component
{
    /**
     * @return array<string, array<string, array{available: float}>|string>
     */
    #[Computed]
    public function balances(): array
    {
        $result = [];

        foreach (app(ExchangeRegistry::class)->all() as $exchange) {
            try {
                $result[$exchange->getName()] = $this->sortByAvailable(
                    $this->mergeUsdIntoUsdt($exchange->getBalances())
                );
            } catch (Throwable $e) {
                $result[$exchange->getName()] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @return array<string, float>
     */
    #[Computed]
    public function totalBalances(): array
    {
        $totals = [];

        foreach ($this->balances as $balances) {
            if (is_string($balances)) {
                continue;
            }

            foreach ($balances as $currency => $balance) {
                $totals[$currency] = ($totals[$currency] ?? 0) + $balance['available'];
            }
        }

        arsort($totals);

        return $totals;
    }

    /**
     * @return array<string, array<string, float>>
     */
    #[Computed]
    public function exchangePercentages(): array
    {
        $percentages = [];

        foreach ($this->balances as $exchangeName => $balances) {
            if (is_string($balances)) {
                continue;
            }

            foreach ($balances as $currency => $balance) {
                $total = $this->totalBalances[$currency] ?? 0;
                $percentages[$exchangeName][$currency] = $total > 0
                    ? round(($balance['available'] / $total) * 100, 1)
                    : 0;
            }
        }

        return $percentages;
    }

    /**
     * Merge USD balance into USDT so both fiat and stablecoin appear as a single currency.
     *
     * @param  array<string, array{available: float}>  $balances
     * @return array<string, array{available: float}>
     */
    private function mergeUsdIntoUsdt(array $balances): array
    {
        if (! isset($balances['USD'])) {
            return $balances;
        }

        $balances['USDT']['available'] = ($balances['USDT']['available'] ?? 0.0) + $balances['USD']['available'];
        unset($balances['USD']);

        return $balances;
    }

    /**
     * @param  array<string, array{available: float}>  $balances
     * @return array<string, array{available: float}>
     */
    private function sortByAvailable(array $balances): array
    {
        uasort($balances, fn (array $a, array $b) => $b['available'] <=> $a['available']);

        return $balances;
    }

    public function forceRefresh(): void
    {
        foreach (app(ExchangeRegistry::class)->all() as $exchange) {
            Cache::forget('exchange_balances_'.strtolower($exchange->getName()));
        }

        unset($this->balances, $this->totalBalances, $this->exchangePercentages);
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.exchange-balances');
    }
}
