<?php

use App\Livewire\ExchangeReserves;
use App\Models\ExchangeReserve;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders with zero reserves when table is empty', function () {
    Livewire::test(ExchangeReserves::class)
        ->assertSet('reserves.Mexc.PEP', 0.0)
        ->assertSet('reserves.CoinEx.PEP', 0.0)
        ->assertSet('reserves.Kraken.PEP', 0.0);
});

it('loads existing reserves from database on mount', function () {
    ExchangeReserve::create(['exchange' => 'Kraken', 'currency' => 'PEP', 'min_amount' => 4_000_000]);

    Livewire::test(ExchangeReserves::class)
        ->assertSet('reserves.Kraken.PEP', 4_000_000.0)
        ->assertSet('reserves.Mexc.PEP', 0.0);
});

it('persists a reserve when updateReserve is called', function () {
    Livewire::test(ExchangeReserves::class)
        ->call('updateReserve', 'Kraken', 'PEP', 2_500_000)
        ->assertSet('reserves.Kraken.PEP', 2_500_000.0);

    expect(ExchangeReserve::getFor('Kraken', 'PEP'))->toBe(2_500_000.0);
});

it('updates an existing reserve without creating a duplicate', function () {
    ExchangeReserve::create(['exchange' => 'Mexc', 'currency' => 'USDT', 'min_amount' => 300]);

    Livewire::test(ExchangeReserves::class)
        ->call('updateReserve', 'Mexc', 'USDT', 500);

    expect(ExchangeReserve::where('exchange', 'Mexc')->where('currency', 'USDT')->count())->toBe(1)
        ->and(ExchangeReserve::getFor('Mexc', 'USDT'))->toBe(500.0);
});

it('ignores negative values and clamps to zero', function () {
    Livewire::test(ExchangeReserves::class)
        ->call('updateReserve', 'CoinEx', 'PEP', -1000);

    expect(ExchangeReserve::getFor('CoinEx', 'PEP'))->toBe(0.0);
});

it('ignores unknown exchange', function () {
    Livewire::test(ExchangeReserves::class)
        ->call('updateReserve', 'Binance', 'PEP', 1_000_000);

    expect(ExchangeReserve::query()->where('exchange', 'Binance')->exists())->toBeFalse();
});
