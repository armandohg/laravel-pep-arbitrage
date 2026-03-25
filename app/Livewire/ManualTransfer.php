<?php

namespace App\Livewire;

use App\Exchanges\ExchangeRegistry;
use App\Rebalance\RebalanceService;
use App\Rebalance\Transfer;
use App\Rebalance\TransferRouteService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ManualTransfer extends Component
{
    public bool $showForm = false;

    public string $transferFrom = '';

    public string $transferTo = '';

    public string $transferCurrency = '';

    public string $transferAmount = '';

    public ?string $transferSuccess = null;

    public ?string $transferError = null;

    public function toggleForm(): void
    {
        $this->showForm = ! $this->showForm;
    }

    public function submitTransfer(RebalanceService $rebalanceService, TransferRouteService $routeService): void
    {
        $this->transferSuccess = null;
        $this->transferError = null;

        $this->validate(
            [
                'transferFrom' => ['required', 'in:Mexc,CoinEx,Kraken'],
                'transferTo' => ['required', 'in:Mexc,CoinEx,Kraken', 'different:transferFrom'],
                'transferCurrency' => ['required', 'in:PEP,USDT'],
                'transferAmount' => ['required', 'numeric', 'min:0.01'],
            ],
            messages: ['transferTo.different' => 'Origin and destination must be different.'],
            attributes: [
                'transferFrom' => 'origin',
                'transferTo' => 'destination',
                'transferCurrency' => 'currency',
                'transferAmount' => 'amount',
            ],
        );

        try {
            $route = $routeService->getRouteForTransfer(
                $this->transferFrom,
                $this->transferTo,
                $this->transferCurrency,
            );

            $krakenStep = null;
            if ($this->transferFrom === 'Kraken' && $this->transferCurrency === 'USDT') {
                $krakenStep = "buy {$this->transferAmount} USDT on Kraken first";
            } elseif ($this->transferTo === 'Kraken' && $this->transferCurrency === 'USDT') {
                $krakenStep = 'sell USDT→USD after deposit confirms on Kraken';
            }

            $transfer = new Transfer(
                fromExchange: $this->transferFrom,
                toExchange: $this->transferTo,
                currency: $this->transferCurrency,
                amount: (float) $this->transferAmount,
                network: $route['network_code'],
                networkId: $route['network_id'],
                address: $route['address'],
                networkFee: $route['fee'],
                memo: $route['memo'],
                krakenStep: $krakenStep,
                withdrawKey: $route['withdraw_key'] ?? null,
            );

            $rebalanceService->executeTransfer($transfer);

            $this->reset(['transferFrom', 'transferTo', 'transferCurrency', 'transferAmount']);
            $this->showForm = false;
            $this->transferSuccess = 'Transfer initiated successfully.';
        } catch (\Throwable $e) {
            $this->transferError = $e->getMessage();
        }
    }

    #[Computed]
    public function originBalance(): ?float
    {
        if ($this->transferFrom === '' || $this->transferCurrency === '') {
            return null;
        }

        try {
            $balances = app(ExchangeRegistry::class)->get($this->transferFrom)->getBalances();
            $currency = $this->transferCurrency;

            // Kraken holds USD, not USDT — treat them as equivalent
            if ($currency === 'USDT' && ! isset($balances['USDT']) && isset($balances['USD'])) {
                $currency = 'USD';
            }

            return $balances[$currency]['available'] ?? 0.0;
        } catch (\Throwable) {
            return null;
        }
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.manual-transfer');
    }
}
