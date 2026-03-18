<?php

use App\Models\BalanceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Chart data grouped by currency, downsampled to hourly averages.
     *
     * @return array<string, array{labels: string[], data: float[]}>
     */
    #[Computed]
    public function chartData(): array
    {
        $since = Carbon::now()->subDays(14);

        $hourBucket = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00:00', snapped_at)"
            : "DATE_FORMAT(snapped_at, '%Y-%m-%d %H:00:00')";

        $snapshots = BalanceSnapshot::query()
            ->selectRaw("currency, AVG(total_available) as total_available, {$hourBucket} as snapped_at")
            ->where('snapped_at', '>=', $since)
            ->groupByRaw("currency, {$hourBucket}")
            ->orderBy('snapped_at')
            ->get();

        $byTime = [];

        foreach ($snapshots as $record) {
            $currency = $record->currency === 'USD' ? 'USDT' : $record->currency;
            $key = (string) $record->snapped_at;
            $byTime[$currency][$key] = ($byTime[$currency][$key] ?? 0.0) + (float) $record->total_available;
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
