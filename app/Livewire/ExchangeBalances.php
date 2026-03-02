<?php

namespace App\Livewire;

use App\Exchanges\ExchangeRegistry;
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
                $result[$exchange->getName()] = $this->sortByAvailable($exchange->getBalances());
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
     * @param  array<string, array{available: float}>  $balances
     * @return array<string, array{available: float}>
     */
    private function sortByAvailable(array $balances): array
    {
        uasort($balances, fn (array $a, array $b) => $b['available'] <=> $a['available']);

        return $balances;
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.exchange-balances');
    }
}
