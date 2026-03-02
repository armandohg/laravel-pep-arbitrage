<?php

use App\Models\BalanceSnapshot;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Chart data grouped by currency.
     *
     * @return array<string, array{labels: string[], data: float[]}>
     */
    #[Computed]
    public function chartData(): array
    {
        $since = Carbon::now()->subDays(14);

        $snapshots = BalanceSnapshot::query()
            ->where('snapped_at', '>=', $since)
            ->orderBy('snapped_at')
            ->get(['currency', 'total_available', 'snapped_at']);

        $charts = [];

        foreach ($snapshots->groupBy('currency') as $currency => $records) {
            $decimals = $currency === 'PEP' ? 0 : 4;

            $charts[$currency] = [
                'labels' => $records->map(fn ($r) => $r->snapped_at->format('M j H:i'))->values()->all(),
                'data' => $records->map(fn ($r) => round($r->total_available, $decimals))->values()->all(),
            ];
        }

        return $charts;
    }
};
