<?php

use App\Models\ArbitrageSettings;
use App\Models\SpreadSnapshot;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Chart datasets grouped by exchange pair direction.
     *
     * @return array{labels: string[], datasets: array<int, array{label: string, data: float[]}>}
     */
    #[Computed]
    public function chartData(): array
    {
        $since = Carbon::now()->subHours(24);

        $snapshots = SpreadSnapshot::query()
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get(['buy_exchange', 'sell_exchange', 'spread_ratio', 'recorded_at']);

        if ($snapshots->isEmpty()) {
            return ['labels' => [], 'datasets' => []];
        }

        // Collect all unique timestamps and group data by pair.
        $labels = [];
        $byPair = [];

        foreach ($snapshots as $snap) {
            $label = $snap->recorded_at->format('H:i');
            $labels[] = $label;
            $pair = "{$snap->buy_exchange} → {$snap->sell_exchange}";
            $byPair[$pair][$label] = round($snap->spread_ratio * 100, 4);
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
