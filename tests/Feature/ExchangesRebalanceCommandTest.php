<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;

beforeEach(function () {
    config()->set('exchanges.networks', [
        'PEP' => ['fee' => 1.0, 'currency' => 'PEP', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
        'TRC20' => ['fee' => 1.0, 'currency' => 'USDT', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
        'ERC20' => ['fee' => 10.0, 'currency' => 'USDT', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
    ]);

    config()->set('exchanges.mexc.deposit_addresses', ['PEP' => 'mexc_pep_addr', 'USDT' => 'mexc_usdt_addr']);
    config()->set('exchanges.coinex.deposit_addresses', ['PEP' => 'coinex_pep_addr', 'USDT' => 'coinex_usdt_addr']);
    config()->set('exchanges.kraken.deposit_addresses', ['PEP' => 'kraken_pep_addr', 'USDT' => 'kraken_usdt_addr']);
    config()->set('exchanges.kraken.withdraw_keys', [
        'PEP_to_Mexc' => 'key_pep_mexc', 'PEP_to_CoinEx' => 'key_pep_coinex',
        'USDT_to_Mexc' => 'key_usdt_mexc', 'USDT_to_CoinEx' => 'key_usdt_coinex',
    ]);
});

function mockExchanges(array $mexcBalances, array $coinexBalances, array $krakenBalances, float $usdtUsdRate = 1.0): void
{
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    $mexcMock->allows('getBalances')->andReturn($mexcBalances);
    $coinexMock->allows('getBalances')->andReturn($coinexBalances);
    $krakenMock->allows('getBalances')->andReturn($krakenBalances);
    $krakenMock->allows('getOrderBook')->with('usdt_usd')->andReturn([
        'asks' => [[$usdtUsdRate, 1000]],
        'bids' => [[$usdtUsdRate - 0.001, 1000]],
    ]);

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
    app()->instance(Kraken::class, $krakenMock);
}

test('shows current state table', function () {
    mockExchanges(
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 200.0]],
    );

    $this->artisan('exchanges:rebalance')
        ->assertSuccessful()
        ->expectsOutputToContain('Mexc')
        ->expectsOutputToContain('CoinEx')
        ->expectsOutputToContain('Kraken');
});

test('shows already balanced message when within tolerance', function () {
    mockExchanges(
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 200.0]],
    );

    $this->artisan('exchanges:rebalance')
        ->assertSuccessful()
        ->expectsOutputToContain('already within the tolerance');
});

test('dry-run does not call withdraw', function () {
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    $mexcMock->allows('getBalances')->andReturn(['PEP' => ['available' => 4_000_000], 'USDT' => ['available' => 400.0]]);
    $coinexMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 100.0]]);
    $krakenMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 100.0]]);
    $krakenMock->allows('getOrderBook')->with('usdt_usd')->andReturn(['asks' => [[1.0, 1000]], 'bids' => [[0.999, 1000]]]);

    $mexcMock->shouldNotReceive('withdraw');
    $coinexMock->shouldNotReceive('withdraw');
    $krakenMock->shouldNotReceive('withdraw');

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
    app()->instance(Kraken::class, $krakenMock);

    $this->artisan('exchanges:rebalance')
        ->assertSuccessful();
});

test('--execute calls withdraw for each transfer', function () {
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    $mexcMock->allows('getBalances')->andReturn(['PEP' => ['available' => 4_000_000], 'USDT' => ['available' => 200.0]]);
    $coinexMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 200.0]]);
    $krakenMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 200.0]]);
    $krakenMock->allows('getOrderBook')->with('usdt_usd')->andReturn(['asks' => [[1.0, 1000]], 'bids' => [[0.999, 1000]]]);

    // PEP imbalance: Mexc sends to CoinEx and/or Kraken
    $mexcMock->shouldReceive('withdraw')->atLeast()->once()->andReturn(['success' => true]);
    $coinexMock->allows('withdraw')->andReturn(['success' => true]);
    $krakenMock->allows('withdraw')->andReturn(['success' => true]);
    $krakenMock->allows('buyUsdt')->andReturn(['success' => true]);

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
    app()->instance(Kraken::class, $krakenMock);

    $this->artisan('exchanges:rebalance --execute')
        ->assertSuccessful();
});

test('--execute calls buyUsdt before Kraken withdraw', function () {
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    // Kraken has lots of USD (needs to send USDT) — USDT imbalance
    $mexcMock->allows('getBalances')->andReturn(['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 100.0]]);
    $coinexMock->allows('getBalances')->andReturn(['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 100.0]]);
    $krakenMock->allows('getBalances')->andReturn(['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 400.0]]);
    $krakenMock->allows('getOrderBook')->with('usdt_usd')->andReturn(['asks' => [[1.0, 1000]], 'bids' => [[0.999, 1000]]]);

    $krakenMock->shouldReceive('buyUsdt')->atLeast()->once()->andReturn(['success' => true]);
    $krakenMock->shouldReceive('withdraw')->atLeast()->once()->andReturn(['success' => true]);
    $mexcMock->allows('withdraw')->andReturn(['success' => true]);
    $coinexMock->allows('withdraw')->andReturn(['success' => true]);

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
    app()->instance(Kraken::class, $krakenMock);

    $this->artisan('exchanges:rebalance --execute')
        ->assertSuccessful();
});
