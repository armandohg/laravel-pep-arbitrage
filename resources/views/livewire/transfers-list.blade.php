<div>
    {{-- Header --}}
    <div class="mb-6">
        <flux:heading size="xl">Transfers</flux:heading>
        <flux:text class="mt-1">Rebalance transfers and their tracking status</flux:text>
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
    <div class="mb-4 flex flex-wrap items-end gap-3">
        {{-- Status buttons --}}
        <div class="flex flex-wrap gap-2">
            @foreach (['', 'unsettled', 'settled', 'failed'] as $status)
                <flux:button
                    wire:click="setFilter('{{ $status }}')"
                    size="sm"
                    variant="{{ $statusFilter === $status ? 'primary' : 'ghost' }}"
                >{{ $status ? ucfirst($status) : 'All' }}</flux:button>
            @endforeach
        </div>

        {{-- Column filters --}}
        <div class="flex flex-wrap gap-3 sm:ml-auto">
            <flux:select wire:model.live="filterFrom" size="sm" placeholder="Origin" class="w-36">
                <flux:select.option value="">All origins</flux:select.option>
                <flux:select.option value="Mexc">MEXC</flux:select.option>
                <flux:select.option value="CoinEx">CoinEx</flux:select.option>
                <flux:select.option value="Kraken">Kraken</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="filterTo" size="sm" placeholder="Destination" class="w-36">
                <flux:select.option value="">All destinations</flux:select.option>
                <flux:select.option value="Mexc">MEXC</flux:select.option>
                <flux:select.option value="CoinEx">CoinEx</flux:select.option>
                <flux:select.option value="Kraken">Kraken</flux:select.option>
            </flux:select>

            <flux:select wire:model.live="filterCurrency" size="sm" placeholder="Currency" class="w-32">
                <flux:select.option value="">All currencies</flux:select.option>
                <flux:select.option value="PEP">PEP</flux:select.option>
                <flux:select.option value="USDT">USDT</flux:select.option>
            </flux:select>
        </div>
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
                        <th class="px-4 py-3"></th>
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
                                    <div class="flex items-center gap-1.5">
                                        <span title="{{ $transfer->tx_hash }}">
                                            {{ substr($transfer->tx_hash, 0, 10) }}…
                                        </span>
                                        <button
                                            x-data="{ copied: false }"
                                            x-on:click="navigator.clipboard.writeText('{{ $transfer->tx_hash }}').then(() => { copied = true; setTimeout(() => copied = false, 1500) })"
                                            x-tooltip="{ content: copied ? 'Copied!' : 'Copy tx hash', delay: 0 }"
                                            class="text-zinc-400 transition hover:text-zinc-600 dark:hover:text-zinc-200"
                                        >
                                            <svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-4 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                            </svg>
                                            <svg x-show="copied" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                            </svg>
                                        </button>
                                        @if ($transfer->currency === 'PEP')
                                            <a
                                                href="https://pepeblocks.com/tx/{{ $transfer->tx_hash }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                x-tooltip="{ content: 'View on Pepeblocks', delay: 0 }"
                                                class="text-zinc-400 transition hover:text-blue-600 dark:hover:text-blue-400"
                                            >
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                </svg>
                                            </a>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-zinc-500 dark:text-zinc-400">
                                {{ $transfer->created_at->format('M d, H:i') }}
                            </td>

                            <td class="px-4 py-3 text-right">
                                <flux:button
                                    icon="arrow-path"
                                    size="sm"
                                    variant="ghost"
                                    wire:click="resetToPending({{ $transfer->id }})"
                                    wire:confirm="Reset transfer #{{ $transfer->id }} to pending? It will be re-tracked on the next run."
                                    x-tooltip="{ content: 'Set as pending', delay: 0 }"
                                />
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
