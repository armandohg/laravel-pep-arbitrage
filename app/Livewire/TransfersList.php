<?php

namespace App\Livewire;

use App\Models\RebalanceTransfer;
use App\Rebalance\RebalanceService;
use App\Rebalance\Transfer;
use App\Rebalance\TransferRouteService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Transfers')]
class TransfersList extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $filterFrom = '';

    public string $filterTo = '';

    public string $filterCurrency = '';

    // Manual transfer form
    public bool $showTransferForm = false;

    public string $transferFrom = '';

    public string $transferTo = '';

    public string $transferCurrency = '';

    public string $transferAmount = '';

    public ?string $transferSuccess = null;

    public ?string $transferError = null;

    public function setFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    public function updatedFilterFrom(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTo(): void
    {
        $this->resetPage();
    }

    public function updatedFilterCurrency(): void
    {
        $this->resetPage();
    }

    public function toggleTransferForm(): void
    {
        $this->showTransferForm = ! $this->showTransferForm;
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
            $this->showTransferForm = false;
            $this->transferSuccess = 'Transfer initiated successfully.';
        } catch (\Throwable $e) {
            $this->transferError = $e->getMessage();
        }
    }

    public function resetToPending(int $transferId): void
    {
        RebalanceTransfer::findOrFail($transferId)->resetToPending();
    }

    #[Computed]
    public function transfers(): LengthAwarePaginator
    {
        return RebalanceTransfer::query()
            ->when($this->statusFilter === 'unsettled', fn ($q) => $q->whereNull('settled_at'))
            ->when($this->statusFilter === 'settled', fn ($q) => $q->whereNotNull('settled_at'))
            ->when($this->statusFilter === 'failed', fn ($q) => $q->where('withdrawal_status', 'failed'))
            ->when($this->filterFrom !== '', fn ($q) => $q->where('from_exchange', $this->filterFrom))
            ->when($this->filterTo !== '', fn ($q) => $q->where('to_exchange', $this->filterTo))
            ->when($this->filterCurrency !== '', fn ($q) => $q->where('currency', $this->filterCurrency))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function totalCount(): int
    {
        return RebalanceTransfer::count();
    }

    #[Computed]
    public function unsettledCount(): int
    {
        return RebalanceTransfer::whereNull('settled_at')->count();
    }

    #[Computed]
    public function failedCount(): int
    {
        return RebalanceTransfer::where('withdrawal_status', 'failed')->count();
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.transfers-list');
    }
}
