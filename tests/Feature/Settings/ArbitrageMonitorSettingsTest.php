<?php

use App\Livewire\Settings\ArbitrageMonitor;
use App\Models\ArbitrageSettings;
use App\Models\User;
use Livewire\Livewire;

test('arbitrage monitor settings page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/settings/arbitrage')->assertOk();
});

test('form loads with defaults from database', function () {
    $this->actingAs(User::factory()->create());

    ArbitrageSettings::current(); // ensure singleton exists with defaults

    Livewire::test(ArbitrageMonitor::class)
        ->assertSet('discoveryInterval', 5)
        ->assertSet('minProfitRatio', 0.003)
        ->assertSet('sustainDuration', 10)
        ->assertSet('sustainInterval', 2)
        ->assertSet('stability', 0.5)
        ->assertSet('minAmount', 0.0)
        ->assertSet('executeOrders', false);
});

test('form loads values from existing settings', function () {
    $this->actingAs(User::factory()->create());

    ArbitrageSettings::current()->update([
        'discovery_interval' => 15,
        'min_profit_ratio' => 0.01,
        'sustain_duration' => 30,
        'sustain_interval' => 5,
        'stability' => 1.0,
        'min_amount' => 10.0,
        'execute_orders' => true,
    ]);

    Livewire::test(ArbitrageMonitor::class)
        ->assertSet('discoveryInterval', 15)
        ->assertSet('minProfitRatio', 0.01)
        ->assertSet('sustainDuration', 30)
        ->assertSet('sustainInterval', 5)
        ->assertSet('stability', 1.0)
        ->assertSet('minAmount', 10.0)
        ->assertSet('executeOrders', true);
});

test('settings are updated and saved event dispatched', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ArbitrageMonitor::class)
        ->set('discoveryInterval', 10)
        ->set('minProfitRatio', 0.005)
        ->set('sustainDuration', 20)
        ->set('sustainInterval', 3)
        ->set('stability', 0.8)
        ->set('minAmount', 5.0)
        ->set('executeOrders', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('settings-saved');

    $settings = ArbitrageSettings::current()->refresh();

    expect($settings->discovery_interval)->toBe(10);
    expect($settings->min_profit_ratio)->toBe(0.005);
    expect($settings->sustain_duration)->toBe(20);
    expect($settings->sustain_interval)->toBe(3);
    expect($settings->stability)->toBe(0.8);
    expect($settings->min_amount)->toBe(5.0);
    expect($settings->execute_orders)->toBeTrue();
});

test('validation rejects discovery interval below 1', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ArbitrageMonitor::class)
        ->set('discoveryInterval', 0)
        ->call('save')
        ->assertHasErrors(['discoveryInterval']);
});

test('validation rejects sustain duration below 1', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ArbitrageMonitor::class)
        ->set('sustainDuration', 0)
        ->call('save')
        ->assertHasErrors(['sustainDuration']);
});

test('validation rejects sustain interval below 1', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ArbitrageMonitor::class)
        ->set('sustainInterval', 0)
        ->call('save')
        ->assertHasErrors(['sustainInterval']);
});

test('validation rejects min profit ratio above 1', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ArbitrageMonitor::class)
        ->set('minProfitRatio', 1.5)
        ->call('save')
        ->assertHasErrors(['minProfitRatio']);
});

test('validation rejects negative min amount', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ArbitrageMonitor::class)
        ->set('minAmount', -1.0)
        ->call('save')
        ->assertHasErrors(['minAmount']);
});
