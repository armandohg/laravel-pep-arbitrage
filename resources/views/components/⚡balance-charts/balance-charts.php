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

        // Index by [currency][snapped_at] for easy USD→USDT merging.
        $byTime = [];

        foreach ($snapshots as $record) {
            $currency = $record->currency === 'USD' ? 'USDT' : $record->currency;
            $key = $record->snapped_at->toDateTimeString();
            $byTime[$currency][$key] = ($byTime[$currency][$key] ?? 0.0) + $record->total_available;
        }

        $charts = [];

        foreach ($byTime as $currency => $entries) {
            $decimals = $currency === 'PEP' ? 0 : 4;
            $charts[$currency] = [
                'labels' => array_map(
                    fn ($ts) => Carbon::parse($ts)->format('M j H:i'),
                    array_keys($entries),
                ),
                'data' => array_map(fn ($v) => round($v, $decimals), array_values($entries)),
            ];
        }

        return $charts;
    }
};
