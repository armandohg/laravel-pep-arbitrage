<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Arbitrage Monitor Settings') }}</flux:heading>

    <x-settings.layout :heading="__('Arbitrage Monitor')" :subheading="__('Configure parameters for the arbitrage discovery loop')">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:input
                wire:model="discoveryInterval"
                :label="__('Discovery Interval (seconds)')"
                type="number"
                min="1"
                :description="__('How often the discovery loop polls all exchange pairs.')"
            />

            <flux:input
                wire:model="minProfitRatio"
                :label="__('Minimum Profit Ratio')"
                type="number"
                step="0.0001"
                min="0"
                max="1"
                :description="__('Minimum profit ratio to consider an opportunity (e.g. 0.003 = 0.3%).')"
            />

            <flux:input
                wire:model="sustainDuration"
                :label="__('Sustain Duration (seconds)')"
                type="number"
                min="1"
                :description="__('How long the opportunity must hold before executing.')"
            />

            <flux:input
                wire:model="sustainInterval"
                :label="__('Sustain Check Interval (seconds)')"
                type="number"
                min="1"
                :description="__('How often to re-check the opportunity during the sustain phase.')"
            />

            <flux:input
                wire:model="stability"
                :label="__('Stability Tolerance (%)')"
                type="number"
                step="0.1"
                min="0"
                :description="__('Allowed profit drift during sustain phase (e.g. 0.5 = ±0.5%).')"
            />

            <flux:input
                wire:model="minAmount"
                :label="__('Minimum Trade Amount (USDT)')"
                type="number"
                step="0.01"
                min="0"
                :description="__('Minimum trade size in USDT to consider an opportunity.')"
            />

            <flux:switch
                wire:model="executeOrders"
                :label="__('Execute Real Orders')"
                :description="__('When enabled, the monitor will place real buy/sell orders when an opportunity is confirmed.')"
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>

                <x-action-message class="me-3" on="settings-saved">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
