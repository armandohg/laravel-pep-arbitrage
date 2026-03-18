<?php

use App\Models\ArbitrageSettings;
use App\Models\SpreadSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Chart datasets grouped by exchange pair direction, downsampled to 5-minute buckets.
     *
     * @return array{labels: string[], datasets: array<int, array{label: string, data: float[]}>}
     */
    #[Computed]
    public function chartData(): array
    {
        $since = Carbon::now()->subHours(24);

        $fiveMinBucket = DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H:', recorded_at) || printf('%02d', cast(strftime('%M', recorded_at) as integer) / 5 * 5) || ':00'"
            : "DATE_FORMAT(DATE_SUB(recorded_at, INTERVAL MINUTE(recorded_at) % 5 MINUTE), '%Y-%m-%d %H:%i:00')";

        $snapshots = SpreadSnapshot::query()
            ->selectRaw("buy_exchange, sell_exchange, MAX(spread_ratio) as spread_ratio, {$fiveMinBucket} as recorded_at")
            ->where('recorded_at', '>=', $since)
            ->groupByRaw("buy_exchange, sell_exchange, {$fiveMinBucket}")
            ->orderBy('recorded_at')
            ->get();

        if ($snapshots->isEmpty()) {
            return ['labels' => [], 'datasets' => []];
        }

        $labels = [];
        $byPair = [];

        foreach ($snapshots as $snap) {
            $label = Carbon::parse($snap->recorded_at)->format('H:i');
            $labels[] = $label;
            $pair = "{$snap->buy_exchange} → {$snap->sell_exchange}";
            $byPair[$pair][$label] = round((float) $snap->spread_ratio * 100, 4);
        }

        $labels = array_values(array_unique($labels));

        $colors = [
            'rgb(99, 102, 241)',
            'rgb(234, 179, 8)',
            'rgb(249, 115, 22)',
            'rgb(34, 197, 94)',
            'rgb(236, 72, 153)',
            'rgb(20, 184, 166)',
        ];

        $datasets = [];
        $colorIndex = 0;

        foreach ($byPair as $pair => $entries) {
            $data = array_map(fn (string $label) => $entries[$label] ?? null, $labels);
            $color = $colors[$colorIndex % count($colors)];
            $datasets[] = [
                'label' => $pair,
                'data' => $data,
                'color' => $color,
            ];
            $colorIndex++;
        }

        $minProfit = round(ArbitrageSettings::current()->min_profit_ratio * 100, 4);

        return ['labels' => $labels, 'datasets' => $datasets, 'minProfit' => $minProfit];
    }
};
