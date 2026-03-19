<?php

use App\Models\RebalanceTransfer;
use App\Models\User;
use App\Rebalance\RebalanceService;
use App\Rebalance\TransferRouteService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders without errors', function () {
    Livewire::test('transfers-list')
        ->assertOk();
});

it('hides the transfer form by default', function () {
    Livewire::test('transfers-list')
        ->assertSet('showTransferForm', false);
});

it('toggles the transfer form open and closed', function () {
    Livewire::test('transfers-list')
        ->assertSet('showTransferForm', false)
        ->call('toggleTransferForm')
        ->assertSet('showTransferForm', true)
        ->call('toggleTransferForm')
        ->assertSet('showTransferForm', false);
});

it('shows validation errors when submitting an empty form', function () {
    Livewire::test('transfers-list')
        ->call('submitTransfer')
        ->assertHasErrors(['transferFrom', 'transferTo', 'transferCurrency', 'transferAmount']);
});

it('rejects same origin and destination exchange', function () {
    Livewire::test('transfers-list')
        ->set('transferFrom', 'Mexc')
        ->set('transferTo', 'Mexc')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', '500000')
        ->call('submitTransfer')
        ->assertHasErrors(['transferTo']);
});

it('rejects invalid exchange names', function () {
    Livewire::test('transfers-list')
        ->set('transferFrom', 'Binance')
        ->set('transferTo', 'Mexc')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', '500000')
        ->call('submitTransfer')
        ->assertHasErrors(['transferFrom']);
});

it('rejects non-numeric amount', function () {
    Livewire::test('transfers-list')
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

    Livewire::test('transfers-list')
        ->set('transferFrom', 'Mexc')
        ->set('transferTo', 'CoinEx')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', '500000')
        ->call('submitTransfer')
        ->assertHasNoErrors()
        ->assertSet('showTransferForm', false)
        ->assertSet('transferFrom', '')
        ->assertSet('transferAmount', '')
        ->assertSet('transferSuccess', 'Transfer initiated successfully.');
});

it('shows an error message when no route is found', function () {
    $this->mock(TransferRouteService::class)
        ->shouldReceive('getRouteForTransfer')
        ->once()
        ->andThrow(new RuntimeException('No active transfer route found for PEP from Mexc to Kraken'));

    Livewire::test('transfers-list')
        ->set('showTransferForm', true)
        ->set('transferFrom', 'Mexc')
        ->set('transferTo', 'Kraken')
        ->set('transferCurrency', 'PEP')
        ->set('transferAmount', '500000')
        ->call('submitTransfer')
        ->assertHasNoErrors()
        ->assertSet('transferError', 'No active transfer route found for PEP from Mexc to Kraken')
        ->assertSet('showTransferForm', true);
});

it('filters transfers by origin exchange', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc', 'to_exchange' => 'CoinEx',
        'currency' => 'USDT', 'amount' => 100, 'network' => 'TRC20',
        'withdrawal_status' => 'completed', 'expires_at' => now()->addHour(),
    ]);
    RebalanceTransfer::create([
        'from_exchange' => 'CoinEx', 'to_exchange' => 'Mexc',
        'currency' => 'PEP', 'amount' => 500000, 'network' => 'PEP',
        'withdrawal_status' => 'pending', 'expires_at' => now()->addHour(),
    ]);

    $component = Livewire::test('transfers-list')
        ->set('filterFrom', 'Mexc');

    expect($component->get('transfers')->total())->toBe(1);
});

it('filters transfers by destination exchange', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc', 'to_exchange' => 'CoinEx',
        'currency' => 'USDT', 'amount' => 100, 'network' => 'TRC20',
        'withdrawal_status' => 'completed', 'expires_at' => now()->addHour(),
    ]);
    RebalanceTransfer::create([
        'from_exchange' => 'CoinEx', 'to_exchange' => 'Kraken',
        'currency' => 'PEP', 'amount' => 500000, 'network' => 'PEP',
        'withdrawal_status' => 'pending', 'expires_at' => now()->addHour(),
    ]);

    $component = Livewire::test('transfers-list')
        ->set('filterTo', 'Kraken');

    expect($component->get('transfers')->total())->toBe(1);
});

it('filters transfers by currency', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc', 'to_exchange' => 'CoinEx',
        'currency' => 'USDT', 'amount' => 100, 'network' => 'TRC20',
        'withdrawal_status' => 'completed', 'expires_at' => now()->addHour(),
    ]);
    RebalanceTransfer::create([
        'from_exchange' => 'CoinEx', 'to_exchange' => 'Mexc',
        'currency' => 'PEP', 'amount' => 500000, 'network' => 'PEP',
        'withdrawal_status' => 'pending', 'expires_at' => now()->addHour(),
    ]);

    $component = Livewire::test('transfers-list')
        ->set('filterCurrency', 'PEP');

    expect($component->get('transfers')->total())->toBe(1);
});

it('resets a transfer to pending', function () {
    $transfer = RebalanceTransfer::create([
        'from_exchange' => 'Mexc', 'to_exchange' => 'CoinEx',
        'currency' => 'USDT', 'amount' => 100, 'network' => 'TRC20',
        'withdrawal_id' => 'WD123',
        'withdrawal_status' => 'failed',
        'settled_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    Livewire::test('transfers-list')
        ->call('resetToPending', $transfer->id)
        ->assertHasNoErrors();

    $transfer->refresh();
    expect($transfer->settled_at)->toBeNull()
        ->and($transfer->withdrawal_status)->toBe('pending');
});

it('filters transfers by settled status', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc', 'to_exchange' => 'CoinEx',
        'currency' => 'USDT', 'amount' => 100, 'network' => 'TRC20',
        'withdrawal_status' => 'completed', 'settled_at' => now(),
        'expires_at' => now()->addHour(),
    ]);
    RebalanceTransfer::create([
        'from_exchange' => 'CoinEx', 'to_exchange' => 'Mexc',
        'currency' => 'PEP', 'amount' => 500000, 'network' => 'PEP',
        'withdrawal_status' => 'pending', 'settled_at' => null,
        'expires_at' => now()->addHour(),
    ]);

    $component = Livewire::test('transfers-list')
        ->call('setFilter', 'settled');

    expect($component->get('transfers')->total())->toBe(1);
});
