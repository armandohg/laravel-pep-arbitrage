<?php

namespace App\Livewire\Settings;

use App\Models\ArbitrageSettings;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Title('Arbitrage Monitor settings')]
class ArbitrageMonitor extends Component
{
    #[Validate('required|integer|min:1')]
    public int $discoveryInterval = 5;

    #[Validate('required|numeric|min:0|max:1')]
    public float $minProfitRatio = 0.003;

    #[Validate('required|integer|min:1')]
    public int $sustainDuration = 10;

    #[Validate('required|integer|min:1')]
    public int $sustainInterval = 2;

    #[Validate('required|numeric|min:0')]
    public float $stability = 0.5;

    #[Validate('required|numeric|min:0')]
    public float $minAmount = 0;

    public bool $executeOrders = false;

    public bool $rebalanceEnabled = true;

    public function mount(): void
    {
        $settings = ArbitrageSettings::current();

        $this->discoveryInterval = $settings->discovery_interval;
        $this->minProfitRatio = $settings->min_profit_ratio;
        $this->sustainDuration = $settings->sustain_duration;
        $this->sustainInterval = $settings->sustain_interval;
        $this->stability = $settings->stability;
        $this->minAmount = $settings->min_amount;
        $this->executeOrders = $settings->execute_orders;
        $this->rebalanceEnabled = $settings->rebalance_enabled;
    }

    public function save(): void
    {
        $this->validate();

        ArbitrageSettings::current()->update([
            'discovery_interval' => $this->discoveryInterval,
            'min_profit_ratio' => $this->minProfitRatio,
            'sustain_duration' => $this->sustainDuration,
            'sustain_interval' => $this->sustainInterval,
            'stability' => $this->stability,
            'min_amount' => $this->minAmount,
            'execute_orders' => $this->executeOrders,
            'rebalance_enabled' => $this->rebalanceEnabled,
        ]);

        $this->dispatch('settings-saved');
    }
}
