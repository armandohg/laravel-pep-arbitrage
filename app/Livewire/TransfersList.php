<?php

namespace App\Livewire;

use App\Models\RebalanceTransfer;
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
