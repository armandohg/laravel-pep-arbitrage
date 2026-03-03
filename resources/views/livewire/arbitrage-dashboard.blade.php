<div wire:poll.5s>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <flux:heading size="xl">PEP Arbitrage Monitor</flux:heading>
            <flux:text class="mt-1">
                Live price gap detection across MEXC, CoinEx, and Kraken &mdash; refreshes every 5s
            </flux:text>
        </div>
    </div>

    {{-- Exchange Balances --}}
    <div class="mb-8">
        <livewire:exchange-balances />
    </div>

    {{-- Balance History Charts --}}
    <div class="mb-8">
        <livewire:balance-charts />
    </div>

    {{-- Arbitrage Executions --}}
    <div class="mb-8">
        <livewire:arbitrage-executions />
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Total Detected</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($this->totalCount) }}</p>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Today</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($this->todayCount) }}</p>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Best Profit Ratio</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums">
                {{ $this->bestProfitRatio > 0 ? number_format($this->bestProfitRatio * 100, 2).'%' : '—' }}
            </p>
        </flux:card>
    </div>

    {{-- Profit-level filter --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['', 'Low', 'Medium', 'High', 'VeryHigh', 'Extreme'] as $level)
            <flux:button
                wire:click="setFilter('{{ $level }}')"
                size="sm"
                variant="{{ $profitLevelFilter === $level ? 'primary' : 'ghost' }}"
            >{{ $level ?: 'All' }}</flux:button>
        @endforeach
    </div>

    {{-- Opportunities table --}}
    <flux:table :paginate="$this->opportunities">
        <flux:table.columns>
            <flux:table.column>Detected</flux:table.column>
            <flux:table.column>Exchange Pair</flux:table.column>
            <flux:table.column align="end">Amount (PEP)</flux:table.column>
            <flux:table.column align="end">Profit (USDT)</flux:table.column>
            <flux:table.column align="end">Profit %</flux:table.column>
            <flux:table.column>Level</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->opportunities as $opportunity)
                <flux:table.row :key="$opportunity->id">
                    <flux:table.cell class="text-zinc-500 dark:text-zinc-400 text-sm">
                        {{ $opportunity->created_at->format('M j, H:i:s') }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="font-medium">{{ $opportunity->buy_exchange }}</span>
                        <span class="mx-1 text-zinc-400">→</span>
                        <span class="font-medium">{{ $opportunity->sell_exchange }}</span>
                    </flux:table.cell>

                    <flux:table.cell align="end" class="tabular-nums">
                        {{ number_format($opportunity->amount, 0) }}
                    </flux:table.cell>

                    <flux:table.cell align="end" variant="strong" class="tabular-nums">
                        {{ number_format($opportunity->profit, 4) }}
                    </flux:table.cell>

                    <flux:table.cell align="end" class="tabular-nums font-medium">
                        {{ number_format($opportunity->profit_ratio * 100, 4) }}%
                    </flux:table.cell>

                    <flux:table.cell>
                        <flux:badge
                            size="sm"
                            inset="top bottom"
                            :color="match($opportunity->profit_level) {
                                'Low'      => 'zinc',
                                'Medium'   => 'yellow',
                                'High'     => 'orange',
                                'VeryHigh' => 'red',
                                'Extreme'  => 'purple',
                                default    => 'zinc',
                            }"
                        >{{ $opportunity->profit_level }}</flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-12 text-center">
                        <flux:text class="text-zinc-400">
                            No opportunities detected yet. Run
                            <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-mono dark:bg-zinc-800">
                                php artisan arbitrage:find
                            </code>
                            to start monitoring.
                        </flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
