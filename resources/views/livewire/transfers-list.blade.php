<div>
    <div class="mb-6 flex items-start justify-between">
        <div>
            <flux:heading size="xl">Transfers</flux:heading>
            <flux:text class="mt-1">Rebalance transfers and their tracking status</flux:text>
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Total</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($this->totalCount) }}</p>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">In Flight</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($this->unsettledCount) }}</p>
        </flux:card>

        <flux:card>
            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Failed</flux:text>
            <p class="mt-1 text-3xl font-semibold tabular-nums text-red-500">{{ number_format($this->failedCount) }}</p>
        </flux:card>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['', 'unsettled', 'settled', 'failed'] as $status)
            <flux:button
                wire:click="setFilter('{{ $status }}')"
                size="sm"
                variant="{{ $statusFilter === $status ? 'primary' : 'ghost' }}"
            >{{ $status ? ucfirst($status) : 'All' }}</flux:button>
        @endforeach
    </div>

    {{-- Table --}}
    <flux:card class="p-0">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">ID</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Route</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Currency</th>
                        <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Amount</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Withdrawal</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Deposit</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Tx Hash</th>
                        <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->transfers as $transfer)
                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50" wire:key="transfer-{{ $transfer->id }}">
                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">#{{ $transfer->id }}</td>

                            <td class="px-4 py-3">
                                <span class="font-medium">{{ $transfer->from_exchange }}</span>
                                <span class="mx-1 text-zinc-400">→</span>
                                <span class="font-medium">{{ $transfer->to_exchange }}</span>
                            </td>

                            <td class="px-4 py-3">
                                <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-mono dark:bg-zinc-700">
                                    {{ $transfer->currency }}
                                </span>
                            </td>

                            <td class="px-4 py-3 text-right tabular-nums">
                                {{ number_format($transfer->amount, $transfer->currency === 'PEP' ? 0 : 2) }}
                            </td>

                            <td class="px-4 py-3">
                                @if ($transfer->withdrawal_status === null)
                                    <span class="text-zinc-400">—</span>
                                @elseif ($transfer->withdrawal_status === 'completed')
                                    <flux:badge color="green" size="sm">completed</flux:badge>
                                @elseif ($transfer->withdrawal_status === 'processing')
                                    <flux:badge color="yellow" size="sm">processing</flux:badge>
                                @elseif ($transfer->withdrawal_status === 'failed')
                                    <flux:badge color="red" size="sm">failed</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ $transfer->withdrawal_status }}</flux:badge>
                                @endif
                            </td>

                            <td class="px-4 py-3">
                                @if ($transfer->deposit_confirmed_at)
                                    <flux:badge color="green" size="sm">confirmed</flux:badge>
                                @elseif ($transfer->settled_at && $transfer->withdrawal_status === 'failed')
                                    <flux:badge color="red" size="sm">failed</flux:badge>
                                @elseif ($transfer->tx_hash)
                                    <flux:badge color="yellow" size="sm">confirming</flux:badge>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($transfer->tx_hash)
                                    <span title="{{ $transfer->tx_hash }}">
                                        {{ substr($transfer->tx_hash, 0, 10) }}…
                                    </span>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                {{ $transfer->created_at->format('M d, H:i') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-zinc-400">
                                No transfers found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->transfers->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                {{ $this->transfers->links() }}
            </div>
        @endif
    </flux:card>
</div>
