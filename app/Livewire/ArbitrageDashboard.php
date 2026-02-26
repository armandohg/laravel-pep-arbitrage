<?php

namespace App\Livewire;

use App\Models\ArbitrageOpportunity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('PEP Arbitrage Dashboard')]
class ArbitrageDashboard extends Component
{
    use WithPagination;

    public string $profitLevelFilter = '';

    public function setFilter(string $level): void
    {
        $this->profitLevelFilter = $level;
        $this->resetPage();
    }

    #[Computed]
    public function opportunities(): LengthAwarePaginator
    {
        return ArbitrageOpportunity::query()
            ->when($this->profitLevelFilter, fn ($q) => $q->where('profit_level', $this->profitLevelFilter))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function totalCount(): int
    {
        return ArbitrageOpportunity::count();
    }

    #[Computed]
    public function todayCount(): int
    {
        return ArbitrageOpportunity::whereDate('created_at', today())->count();
    }

    #[Computed]
    public function bestProfitRatio(): float
    {
        return (float) ArbitrageOpportunity::max('profit_ratio');
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.arbitrage-dashboard');
    }
}
