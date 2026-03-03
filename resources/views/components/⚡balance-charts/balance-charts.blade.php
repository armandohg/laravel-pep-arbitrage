<div
    wire:poll.900s
    x-data="{
        hidden: JSON.parse(localStorage.getItem('balance-charts-hidden') ?? '[]'),
        toggle(currency) {
            this.hidden = this.hidden.includes(currency)
                ? this.hidden.filter(c => c !== currency)
                : [...this.hidden, currency];
            localStorage.setItem('balance-charts-hidden', JSON.stringify(this.hidden));
        },
    }"
>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <flux:heading size="lg">Balance History (14 days)</flux:heading>

        <div class="flex flex-wrap items-center gap-2">
            @foreach (array_keys($this->chartData) as $currency)
                <button
                    type="button"
                    @click="toggle('{{ $currency }}')"
                    x-bind:class="hidden.includes('{{ $currency }}')
                        ? 'opacity-40 bg-zinc-100 dark:bg-zinc-800'
                        : 'bg-zinc-100 dark:bg-zinc-800 ring-1 ring-zinc-400 dark:ring-zinc-500'"
                    class="px-2.5 py-1 rounded-lg text-xs font-medium text-zinc-700 dark:text-zinc-300 transition-opacity cursor-pointer hover:opacity-75"
                >{{ $currency }}</button>
            @endforeach

            <flux:button wire:click="$refresh" size="sm" variant="ghost" icon="arrow-path" wire:loading.attr="disabled">
                <span wire:loading.remove>Refresh</span>
                <span wire:loading>Refreshing…</span>
            </flux:button>
        </div>
    </div>

    @if (empty($this->chartData))
        <flux:card>
            <flux:text class="text-sm text-center py-8 text-zinc-400">
                No balance history yet. Run
                <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-mono dark:bg-zinc-800">
                    php artisan exchanges:snapshot-balances
                </code>
                to record the first snapshot.
            </flux:text>
        </flux:card>
    @else
        <div class="grid gap-4 sm:grid-cols-{{ min(count($this->chartData), 3) }}">
            @foreach ($this->chartData as $currency => $chart)
                <template x-if="!hidden.includes('{{ $currency }}')">
                    <flux:card>
                        <flux:heading size="sm" class="mb-3">{{ $currency }}</flux:heading>
                        <div
                            x-data="{
                                chart: null,
                                labels: {{ Js::from($chart['labels']) }},
                                data: {{ Js::from($chart['data']) }},
                                init() {
                                    const ctx = this.$refs.canvas.getContext('2d');
                                    this.chart = new Chart(ctx, {
                                        type: 'line',
                                        data: {
                                            labels: this.labels,
                                            datasets: [{
                                                data: this.data,
                                                borderColor: 'rgb(99, 102, 241)',
                                                backgroundColor: 'rgba(99, 102, 241, 0.08)',
                                                borderWidth: 1.5,
                                                pointRadius: 0,
                                                fill: true,
                                                tension: 0.3,
                                            }]
                                        },
                                        options: {
                                            responsive: true,
                                            animation: false,
                                            interaction: {
                                                mode: 'index',
                                                intersect: false,
                                            },
                                            plugins: {
                                                legend: { display: false },
                                                tooltip: {
                                                    callbacks: {
                                                        label: (ctx) => ` ${ctx.parsed.y.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 6 })}`,
                                                    },
                                                },
                                            },
                                            scales: {
                                                x: {
                                                    ticks: { maxTicksLimit: 7, maxRotation: 0, font: { size: 10 } },
                                                    grid: { display: false },
                                                },
                                                y: {
                                                    beginAtZero: false,
                                                    ticks: { maxTicksLimit: 5, font: { size: 10 } },
                                                },
                                            },
                                        },
                                    });
                                },
                                destroy() {
                                    if (this.chart) {
                                        this.chart.destroy();
                                    }
                                },
                            }"
                        >
                            <canvas x-ref="canvas" class="max-h-48 w-full"></canvas>
                        </div>
                    </flux:card>
                </template>
            @endforeach
        </div>
    @endif
</div>
