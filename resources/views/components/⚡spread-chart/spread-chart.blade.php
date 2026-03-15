<div wire:poll.30s>
    <div class="flex items-center justify-between mb-4">
        <div>
            <flux:heading size="lg">Spread History (24h)</flux:heading>
            <flux:text class="mt-1">Best top-of-book spread per poll across all exchange pairs</flux:text>
        </div>
        <flux:button wire:click="$refresh" size="sm" variant="ghost" icon="arrow-path" wire:loading.attr="disabled">
            <span wire:loading.remove>Refresh</span>
            <span wire:loading>Refreshing…</span>
        </flux:button>
    </div>

    @if (empty($this->chartData['datasets']))
        <flux:card>
            <flux:text class="text-sm text-center py-8 text-zinc-400">
                No spread data yet. Data is collected automatically while
                <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-mono dark:bg-zinc-800">
                    php artisan arbitrage:find
                </code>
                is running.
            </flux:text>
        </flux:card>
    @else
        <flux:card>
            <div
                x-data="{
                    chart: null,
                    labels: {{ Js::from($this->chartData['labels']) }},
                    datasets: {{ Js::from($this->chartData['datasets']) }},
                    minProfit: {{ $this->chartData['minProfit'] ?? 0 }},
                    init() {
                        const ctx = this.$refs.canvas.getContext('2d');
                        this.chart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: this.labels,
                                datasets: this.datasets.map(ds => ({
                                    label: ds.label,
                                    data: ds.data,
                                    borderColor: ds.color,
                                    backgroundColor: ds.color.replace('rgb', 'rgba').replace(')', ', 0.06)'),
                                    borderWidth: 1.5,
                                    pointRadius: 0,
                                    fill: false,
                                    tension: 0.3,
                                    spanGaps: true,
                                })),
                            },
                            options: {
                                responsive: true,
                                animation: false,
                                interaction: {
                                    mode: 'index',
                                    intersect: false,
                                },
                                plugins: {
                                    legend: {
                                        display: true,
                                        position: 'top',
                                        labels: { boxWidth: 12, font: { size: 11 } },
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: (ctx) => ` ${ctx.dataset.label}: ${ctx.parsed.y !== null ? ctx.parsed.y.toFixed(4) + '%' : '—'}`,
                                        },
                                    },
                                    annotation: this.minProfit > 0 ? {
                                        annotations: {
                                            threshold: {
                                                type: 'line',
                                                yMin: this.minProfit,
                                                yMax: this.minProfit,
                                                borderColor: 'rgba(34,197,94,0.5)',
                                                borderWidth: 1,
                                                borderDash: [4, 4],
                                                label: {
                                                    display: true,
                                                    content: 'Min ' + this.minProfit + '%',
                                                    position: 'end',
                                                    font: { size: 10 },
                                                },
                                            },
                                        },
                                    } : {},
                                },
                                scales: {
                                    x: {
                                        ticks: { maxTicksLimit: 12, maxRotation: 0, font: { size: 10 } },
                                        grid: { display: false },
                                    },
                                    y: {
                                        ticks: {
                                            maxTicksLimit: 6,
                                            font: { size: 10 },
                                            callback: (v) => v.toFixed(2) + '%',
                                        },
                                    },
                                },
                            },
                        });
                    },
                    destroy() {
                        if (this.chart) this.chart.destroy();
                    },
                }"
            >
                <canvas x-ref="canvas" class="max-h-64 w-full"></canvas>
            </div>
        </flux:card>
    @endif
</div>
