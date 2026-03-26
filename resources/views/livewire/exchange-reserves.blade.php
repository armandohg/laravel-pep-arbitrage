<div x-data="{ open: false }">
    <button
        @click="open = !open"
        class="flex w-full items-center justify-between mb-4"
    >
        <flux:heading size="lg">Minimum Reserves</flux:heading>
        <flux:icon.chevron-down x-bind:class="open ? 'rotate-180' : ''" class="size-4 text-zinc-400 transition-transform duration-200" />
    </button>

    <flux:card x-show="open" x-collapse>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="py-2 pr-6 text-left font-medium text-zinc-500 dark:text-zinc-400">Exchange</th>
                        @foreach ($currencies as $currency)
                            <th class="py-2 px-3 text-right font-medium text-zinc-500 dark:text-zinc-400">{{ $currency }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($exchanges as $exchange)
                        <tr>
                            <td class="py-2 pr-6 font-medium">{{ $exchange }}</td>
                            @foreach ($currencies as $currency)
                                <td class="py-2 px-3">
                                    <flux:input
                                        type="number"
                                        min="0"
                                        step="1"
                                        size="sm"
                                        class="text-right"
                                        wire:model="reserves.{{ $exchange }}.{{ $currency }}"
                                        wire:blur="updateReserve('{{ $exchange }}', '{{ $currency }}', $event.target.value)"
                                    />
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <flux:text class="mt-3 text-xs text-zinc-400">Values saved on blur. Zero = no reserve (equal split).</flux:text>
    </flux:card>
</div>
