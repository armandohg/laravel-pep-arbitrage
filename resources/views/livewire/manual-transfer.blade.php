<div>
    {{-- Trigger button --}}
    <flux:button
        wire:click="toggleForm"
        size="sm"
        icon="plus"
        variant="primary"
        class="shrink-0"
    >
        New Transfer
    </flux:button>

    {{-- Success banner --}}
    @if ($transferSuccess)
        <div class="mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-950 dark:text-green-300">
            {{ $transferSuccess }}
        </div>
    @endif

    {{-- Transfer Form (collapsible) --}}
    <div x-show="$wire.showForm" x-collapse class="mt-4">
        <flux:card>
            <flux:heading size="lg" class="mb-1">Manual Transfer</flux:heading>
            <flux:text class="mb-5">Initiate a withdrawal from one exchange to another.</flux:text>

            {{-- Error --}}
            @if ($transferError)
                <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
                    {{ $transferError }}
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- From --}}
                <div>
                    <flux:select wire:model.live="transferFrom" label="From exchange" placeholder="Select exchange">
                        <flux:select.option value="Mexc">MEXC</flux:select.option>
                        <flux:select.option value="CoinEx">CoinEx</flux:select.option>
                        <flux:select.option value="Kraken">Kraken</flux:select.option>
                    </flux:select>
                    @error('transferFrom')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- To --}}
                <div>
                    <flux:select wire:model="transferTo" label="To exchange" placeholder="Select exchange">
                        <flux:select.option value="Mexc">MEXC</flux:select.option>
                        <flux:select.option value="CoinEx">CoinEx</flux:select.option>
                        <flux:select.option value="Kraken">Kraken</flux:select.option>
                    </flux:select>
                    @error('transferTo')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Currency --}}
                <div>
                    <flux:select wire:model.live="transferCurrency" label="Currency" placeholder="Select currency">
                        <flux:select.option value="PEP">PEP</flux:select.option>
                        <flux:select.option value="USDT">USDT</flux:select.option>
                    </flux:select>
                    @error('transferCurrency')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Amount --}}
                <div>
                    <flux:input
                        wire:model="transferAmount"
                        label="Amount"
                        type="number"
                        min="0.01"
                        step="any"
                        placeholder="0.00"
                    />
                    @error('transferAmount')
                        <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                    @if ($this->originBalance !== null)
                        <p class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">
                            Available: {{ number_format($this->originBalance, $transferCurrency === 'PEP' ? 0 : 2) }} {{ $transferCurrency }}
                        </p>
                    @endif
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <flux:button
                    wire:click="submitTransfer"
                    wire:loading.attr="disabled"
                    wire:confirm="Are you sure you want to execute this transfer? This will initiate a real withdrawal."
                    variant="primary"
                >
                    <span wire:loading.remove wire:target="submitTransfer">Execute Transfer</span>
                    <span wire:loading wire:target="submitTransfer">Executing…</span>
                </flux:button>

                <flux:button wire:click="toggleForm" variant="ghost">
                    Cancel
                </flux:button>
            </div>
        </flux:card>
    </div>
</div>
