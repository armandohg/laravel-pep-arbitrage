<?php

namespace App\Livewire;

use App\Models\ExchangeReserve;
use Livewire\Component;

class ExchangeReserves extends Component
{
    /** @var array<string, array<string, float>> */
    public array $reserves = [];

    /** @var list<string> */
    public array $exchanges = ['Mexc', 'CoinEx', 'Kraken'];

    /** @var list<string> */
    public array $currencies = ['PEP', 'USDT'];

    public function mount(): void
    {
        $this->loadReserves();
    }

    public function updateReserve(string $exchange, string $currency, mixed $amount): void
    {
        if (! in_array($exchange, $this->exchanges, strict: true)) {
            return;
        }

        if (! in_array($currency, $this->currencies, strict: true)) {
            return;
        }

        $value = max(0.0, (float) $amount);

        ExchangeReserve::query()->updateOrCreate(
            ['exchange' => $exchange, 'currency' => $currency],
            ['min_amount' => $value],
        );

        $this->reserves[$exchange][$currency] = $value;
    }

    private function loadReserves(): void
    {
        $indexed = ExchangeReserve::allIndexed();

        foreach ($this->exchanges as $exchange) {
            foreach ($this->currencies as $currency) {
                $this->reserves[$exchange][$currency] = $indexed[$exchange][$currency] ?? 0.0;
            }
        }
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.exchange-reserves');
    }
}
