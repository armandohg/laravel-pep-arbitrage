<?php

namespace App\Livewire;

use App\Exchanges\ExchangeRegistry;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

class ExchangeBalances extends Component
{
    public function __construct(private readonly ExchangeRegistry $registry) {}

    /**
     * @return array<string, array<string, array{available: float}>|string>
     */
    #[Computed]
    public function balances(): array
    {
        $result = [];

        foreach ($this->registry->all() as $exchange) {
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
