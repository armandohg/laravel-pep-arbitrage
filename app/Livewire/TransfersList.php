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

    public function setFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    #[Computed]
    public function transfers(): LengthAwarePaginator
    {
        return RebalanceTransfer::query()
            ->when($this->statusFilter === 'unsettled', fn ($q) => $q->whereNull('settled_at'))
            ->when($this->statusFilter === 'settled', fn ($q) => $q->whereNotNull('settled_at'))
            ->when($this->statusFilter === 'failed', fn ($q) => $q->where('withdrawal_status', 'failed'))
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
