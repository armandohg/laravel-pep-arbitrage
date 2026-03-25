<?php

use App\Models\RebalanceTransfer;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders without errors', function () {
    Livewire::test('transfers-list')
        ->assertOk();
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

it('resets a transfer to pending and extends expires_at', function () {
    $transfer = RebalanceTransfer::create([
        'from_exchange' => 'Mexc', 'to_exchange' => 'CoinEx',
        'currency' => 'USDT', 'amount' => 100, 'network' => 'TRC20',
        'withdrawal_id' => 'WD123',
        'withdrawal_status' => 'failed',
        'settled_at' => now(),
        'expires_at' => now()->subHours(3),
    ]);

    Livewire::test('transfers-list')
        ->call('resetToPending', $transfer->id)
        ->assertHasNoErrors();

    $transfer->refresh();
    expect($transfer->settled_at)->toBeNull()
        ->and($transfer->withdrawal_status)->toBe('pending')
        ->and($transfer->expires_at->isFuture())->toBeTrue();
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
