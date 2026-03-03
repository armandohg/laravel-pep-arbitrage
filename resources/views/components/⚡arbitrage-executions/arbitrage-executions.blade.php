<div wire:poll.30s>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <flux:heading size="lg">Arbitrage Executions</flux:heading>
            <flux:text class="mt-1">History of executed arbitrage operations</flux:text>
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-4">
        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Executed</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums text-green-600 dark:text-green-400">
                {{ number_format($this->totalExecuted) }}
            </p>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Partial</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums text-yellow-600 dark:text-yellow-400">
                {{ number_format($this->totalPartial) }}
            </p>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Failed</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums text-red-600 dark:text-red-400">
                {{ number_format($this->totalFailed) }}
            </p>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Total Profit (USDT)</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums">
                {{ $this->totalProfit > 0 ? number_format($this->totalProfit, 4) : '—' }}
            </p>
        </flux:card>
    </div>

    {{-- Status filter --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['', 'executed', 'partial', 'failed'] as $status)
            <flux:button
                wire:click="setStatusFilter('{{ $status }}')"
                size="sm"
                variant="{{ $statusFilter === $status ? 'primary' : 'ghost' }}"
            >{{ $status ?: 'All' }}</flux:button>
        @endforeach
    </div>

    {{-- Executions table --}}
    <flux:table :paginate="$this->executions">
        <flux:table.columns>
            <flux:table.column>Executed At</flux:table.column>
            <flux:table.column>Exchange Pair</flux:table.column>
            <flux:table.column align="end">Amount (PEP)</flux:table.column>
            <flux:table.column align="end">Buy Price</flux:table.column>
            <flux:table.column align="end">Sell Price</flux:table.column>
            <flux:table.column align="end">Profit (USDT)</flux:table.column>
            <flux:table.column>Status</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->executions as $execution)
                <flux:table.row :key="$execution->id">
                    <flux:table.cell class="text-zinc-500 dark:text-zinc-400 text-sm">
                        {{ $execution->executed_at?->format('M j, H:i:s') ?? '—' }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <span class="font-medium">{{ $execution->buy_exchange }}</span>
                        <span class="mx-1 text-zinc-400">→</span>
                        <span class="font-medium">{{ $execution->sell_exchange }}</span>
                    </flux:table.cell>

                    <flux:table.cell align="end" class="tabular-nums">
                        {{ $execution->executed_amount ? number_format($execution->executed_amount, 0) : '—' }}
                    </flux:table.cell>

                    <flux:table.cell align="end" class="tabular-nums text-sm">
                        {{ $execution->executed_buy_price ? number_format($execution->executed_buy_price, 8) : '—' }}
                    </flux:table.cell>

                    <flux:table.cell align="end" class="tabular-nums text-sm">
                        {{ $execution->executed_sell_price ? number_format($execution->executed_sell_price, 8) : '—' }}
                    </flux:table.cell>

                    <flux:table.cell align="end" variant="strong" class="tabular-nums">
                        {{ $execution->profit ? number_format($execution->profit, 4) : '—' }}
                    </flux:table.cell>

                    <flux:table.cell>
                        <flux:badge
                            size="sm"
                            inset="top bottom"
                            :color="match($execution->execution_status) {
                                'executed' => 'green',
                                'partial'  => 'yellow',
                                'failed'   => 'red',
                                default    => 'zinc',
                            }"
                            :title="$execution->execution_error ?? ''"
                        >{{ $execution->execution_status }}</flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="py-12 text-center">
                        <flux:text class="text-zinc-400">
                            No executions yet. Run
                            <code class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-mono dark:bg-zinc-800">
                                php artisan arbitrage:find --execute
                            </code>
                            to start executing opportunities.
                        </flux:text>
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
