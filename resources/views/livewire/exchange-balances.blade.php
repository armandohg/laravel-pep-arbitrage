<div wire:poll.30s>
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="lg">Exchange Balances</flux:heading>
        <flux:button wire:click="forceRefresh" size="sm" variant="ghost" icon="arrow-path" wire:loading.attr="disabled">
            <span wire:loading.remove>Refresh</span>
            <span wire:loading>Refreshing…</span>
        </flux:button>
    </div>

    {{-- Total --}}
    @if (! empty($this->totalBalances))
        <flux:card class="mb-4">
            <flux:heading size="sm" class="mb-3">Total (all exchanges)</flux:heading>
            <div class="grid grid-cols-2 gap-x-8 gap-y-2 sm:flex sm:flex-wrap sm:gap-x-10">
                @foreach ($this->totalBalances as $currency => $total)
                    <div class="flex items-baseline gap-2">
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currency }}</flux:text>
                        <span class="font-semibold tabular-nums">
                            {{ $currency === 'PEP'
                                ? number_format($total, 0)
                                : number_format($total, 4) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </flux:card>
    @endif

    @php
        $exchangeCount = collect($this->balances)->filter(fn ($b) => ! is_string($b))->count();
        $ideal = $exchangeCount > 0 ? 100 / $exchangeCount : 100;
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        @foreach ($this->balances as $name => $balances)
            <flux:card>
                <flux:heading size="sm" class="mb-3">{{ $name }}</flux:heading>

                @if (is_string($balances))
                    <flux:text class="text-red-500 text-sm">{{ $balances }}</flux:text>
                @elseif (empty($balances))
                    <flux:text class="text-zinc-400 text-sm">No balances found.</flux:text>
                @else
                    <div class="space-y-2">
                        @foreach ($balances as $currency => $balance)
                            @php
                                $pct = $this->exchangePercentages[$name][$currency] ?? 0;
                                $ratio = $ideal > 0 ? $pct / $ideal : 0;
                                $pctColor = $ratio >= 0.75 ? 'text-green-500' : ($ratio >= 0.5 ? 'text-yellow-400' : 'text-red-500');
                            @endphp
                            <div class="flex items-baseline justify-between">
                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $currency }}</flux:text>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-xs font-medium tabular-nums {{ $pctColor }}">{{ $pct }}%</span>
                                    <span class="text-lg font-semibold tabular-nums">
                                        {{ $currency === 'PEP'
                                            ? number_format($balance['available'], 0)
                                            : number_format($balance['available'], 4) }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        @endforeach
    </div>
</div>
