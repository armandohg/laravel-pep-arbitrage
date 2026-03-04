<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;

beforeEach(function () {
    seedTransferRoutes();
});

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

    $mexcMock->allows('getName')->andReturn('Mexc');
    $coinexMock->allows('getName')->andReturn('CoinEx');
    $krakenMock->allows('getName')->andReturn('Kraken');
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

test('dry-run shows destination address column', function () {
    mockExchanges(
        ['PEP' => ['available' => 4_000_000], 'USDT' => ['available' => 400.0]],
        ['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 100.0]],
        ['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 100.0]],
    );

    $this->artisan('exchanges:rebalance')
        ->assertSuccessful()
        ->expectsOutputToContain('Destination');
});

test('--execute calls withdraw for each transfer', function () {
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    $mexcMock->allows('getName')->andReturn('Mexc');
    $coinexMock->allows('getName')->andReturn('CoinEx');
    $krakenMock->allows('getName')->andReturn('Kraken');
    $mexcMock->allows('getBalances')->andReturn(['PEP' => ['available' => 4_000_000], 'USDT' => ['available' => 200.0]]);
    $coinexMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 200.0]]);
    $krakenMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 200.0]]);
    $krakenMock->allows('getOrderBook')->with('usdt_usd')->andReturn(['asks' => [[1.0, 1000]], 'bids' => [[0.999, 1000]]]);

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

    $mexcMock->allows('getName')->andReturn('Mexc');
    $coinexMock->allows('getName')->andReturn('CoinEx');
    $krakenMock->allows('getName')->andReturn('Kraken');
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

test('--interactive without --execute returns error', function () {
    mockExchanges(
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 200.0]],
    );

    $this->artisan('exchanges:rebalance --interactive')
        ->assertFailed()
        ->expectsOutputToContain('--interactive requires --execute');
});

test('--interactive --execute confirms and executes each transfer', function () {
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    $mexcMock->allows('getName')->andReturn('Mexc');
    $coinexMock->allows('getName')->andReturn('CoinEx');
    $krakenMock->allows('getName')->andReturn('Kraken');
    // Only one transfer needed: Mexc→CoinEx PEP
    $mexcMock->allows('getBalances')->andReturn(['PEP' => ['available' => 3_000_000], 'USDT' => ['available' => 200.0]]);
    $coinexMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 200.0]]);
    $krakenMock->allows('getBalances')->andReturn(['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 200.0]]);
    $krakenMock->allows('getOrderBook')->with('usdt_usd')->andReturn(['asks' => [[1.0, 1000]], 'bids' => [[0.999, 1000]]]);

    $mexcMock->shouldReceive('withdraw')->once()->andReturn(['success' => true]);

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
    app()->instance(Kraken::class, $krakenMock);

    $this->artisan('exchanges:rebalance --execute --interactive')
        ->expectsConfirmation('Execute transfer #1?', 'yes')
        ->assertSuccessful()
        ->expectsOutputToContain('✓ Transfer #1 sent.');
});

test('--interactive --execute skips transfer when user declines', function () {
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    $mexcMock->allows('getName')->andReturn('Mexc');
    $coinexMock->allows('getName')->andReturn('CoinEx');
    $krakenMock->allows('getName')->andReturn('Kraken');
    $mexcMock->allows('getBalances')->andReturn(['PEP' => ['available' => 3_000_000], 'USDT' => ['available' => 200.0]]);
    $coinexMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 200.0]]);
    $krakenMock->allows('getBalances')->andReturn(['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 200.0]]);
    $krakenMock->allows('getOrderBook')->with('usdt_usd')->andReturn(['asks' => [[1.0, 1000]], 'bids' => [[0.999, 1000]]]);

    $mexcMock->shouldNotReceive('withdraw');

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
    app()->instance(Kraken::class, $krakenMock);

    $this->artisan('exchanges:rebalance --execute --interactive')
        ->expectsConfirmation('Execute transfer #1?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('0 executed, 1 skipped');
});

test('--network option forces specific network', function () {
    mockExchanges(
        ['PEP' => ['available' => 4_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 200.0]],
    );

    $this->artisan('exchanges:rebalance --network=PEP')
        ->assertSuccessful()
        ->expectsOutputToContain('[PEP]');
});
