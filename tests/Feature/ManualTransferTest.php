<?php

use App\Models\User;
use App\Rebalance\RebalanceService;
use App\Rebalance\TransferRouteService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders without errors', function () {
    Livewire::test('manual-transfer')
        ->assertOk();
});

it('hides the transfer form by default', function () {
    Livewire::test('manual-transfer')
        ->assertSet('showForm', false);
});

it('toggles the transfer form open and closed', function () {
    Livewire::test('manual-transfer')
        ->assertSet('showForm', false)
        ->call('toggleForm')
        ->assertSet('showForm', true)
        ->call('toggleForm')
        ->assertSet('showForm', false);
});

it('shows validation errors when submitting an empty form', function () {
    Livewire::test('manual-transfer')
        ->call('submitTransfer')
        ->assertHasErrors(['transferFrom', 'transferTo', 'transferCurrency', 'transferAmount']);
});

it('rejects same origin and destination exchange', function () {
    Livewire::test('manual-transfer')
        ->set('transferFrom', 'Mexc')
        ->set('transferTo', 'Mexc')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', '500000')
        ->call('submitTransfer')
        ->assertHasErrors(['transferTo']);
});

it('rejects invalid exchange names', function () {
    Livewire::test('manual-transfer')
        ->set('transferFrom', 'Binance')
        ->set('transferTo', 'Mexc')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', '500000')
        ->call('submitTransfer')
        ->assertHasErrors(['transferFrom']);
});

it('rejects non-numeric amount', function () {
    Livewire::test('manual-transfer')
        ->set('transferFrom', 'Mexc')
        ->set('transferTo', 'CoinEx')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', 'abc')
        ->call('submitTransfer')
        ->assertHasErrors(['transferAmount']);
});

it('executes a valid transfer and resets the form', function () {
    $routeData = [
        'network_code' => 'PEP',
        'network_id' => 'PEP',
        'fee' => 1.0,
        'address' => 'COINEX_PEP_ADDRESS',
        'memo' => null,
        'withdraw_key' => null,
    ];

    $this->mock(TransferRouteService::class)
        ->shouldReceive('getRouteForTransfer')
        ->once()
        ->andReturn($routeData);

    $this->mock(RebalanceService::class)
        ->shouldReceive('executeTransfer')
        ->once();

    Livewire::test('manual-transfer')
        ->set('transferFrom', 'Mexc')
        ->set('transferTo', 'CoinEx')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', '500000')
        ->call('submitTransfer')
        ->assertHasNoErrors()
        ->assertSet('showForm', false)
        ->assertSet('transferFrom', '')
        ->assertSet('transferAmount', '')
        ->assertSet('transferSuccess', 'Transfer initiated successfully.');
});

it('shows an error message when no route is found', function () {
    $this->mock(TransferRouteService::class)
        ->shouldReceive('getRouteForTransfer')
        ->once()
        ->andThrow(new RuntimeException('No active transfer route found for PEP from Mexc to Kraken'));

    Livewire::test('manual-transfer')
        ->set('showForm', true)
        ->set('transferFrom', 'Mexc')
        ->set('transferTo', 'Kraken')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', '500000')
        ->call('submitTransfer')
        ->assertHasNoErrors()
        ->assertSet('transferError', 'No active transfer route found for PEP from Mexc to Kraken')
        ->assertSet('showForm', true);
});
