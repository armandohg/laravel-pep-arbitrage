<?php

namespace App\Livewire;

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class ExchangeBalances extends Component
{
    /**
     * @return array<string, array{available: float}|string>
     */
    #[Computed]
    public function mexcBalances(): array|string
    {
        try {
            return $this->sortByAvailable(app(Mexc::class)->getBalances());
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array<string, array{available: float}|string>
     */
    #[Computed]
    public function coinexBalances(): array|string
    {
        try {
            return $this->sortByAvailable(app(CoinEx::class)->getBalances());
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array<string, array{available: float}|string>
     */
    #[Computed]
    public function krakenBalances(): array|string
    {
        try {
            return $this->sortByAvailable(app(Kraken::class)->getBalances());
        } catch (Throwable $e) {
            return $e->getMessage();
        }
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

    /**
     * @return array<string, float>
     */
    #[Computed]
    public function totalBalances(): array
    {
        $totals = [];

        foreach ([$this->mexcBalances, $this->coinexBalances, $this->krakenBalances] as $balances) {
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

    public function render(): \Illuminate\View\View
    {
        return view('livewire.exchange-balances');
    }
}
