<?php

use App\Models\ArbitrageOpportunity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public function setStatusFilter(string $status): void
    {
        $this->statusFilter = $status;
        $this->resetPage();
    }

    #[Computed]
    public function executions(): LengthAwarePaginator
    {
        return ArbitrageOpportunity::query()
            ->whereNotNull('execution_status')
            ->when($this->statusFilter, fn ($q) => $q->where('execution_status', $this->statusFilter))
            ->latest('executed_at')
            ->paginate(20);
    }

    #[Computed]
    public function totalExecuted(): int
    {
        return ArbitrageOpportunity::where('execution_status', 'executed')->count();
    }

    #[Computed]
    public function totalPartial(): int
    {
        return ArbitrageOpportunity::where('execution_status', 'partial')->count();
    }

    #[Computed]
    public function totalFailed(): int
    {
        return ArbitrageOpportunity::where('execution_status', 'failed')->count();
    }

    #[Computed]
    public function totalProfit(): float
    {
        return (float) ArbitrageOpportunity::where('execution_status', 'executed')->sum('profit');
    }
};
