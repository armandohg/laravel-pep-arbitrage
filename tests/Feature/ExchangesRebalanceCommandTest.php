<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use App\Models\ExchangeNetwork;
use App\Models\ExchangeWallet;
use App\Models\TransferRoute;

beforeEach(function () {
    seedTransferRoutes();
});

function seedTransferRoutes(): void
{
    $routes = [
        ['Mexc', 'CoinEx', 'PEP', 'PEP', 'coinex_pep_addr', 1.0],
        ['Mexc', 'Kraken', 'PEP', 'PEP', 'kraken_pep_addr', 1.0],
        ['CoinEx', 'Mexc', 'PEP', 'PEP', 'mexc_pep_addr', 1.0],
        ['CoinEx', 'Kraken', 'PEP', 'PEP', 'kraken_pep_addr', 1.0],
        ['Kraken', 'Mexc', 'PEP', 'PEP', 'key_pep_mexc', 1.0],
        ['Kraken', 'CoinEx', 'PEP', 'PEP', 'key_pep_coinex', 1.0],
        ['Mexc', 'CoinEx', 'USDT', 'TRC20', 'coinex_usdt_addr', 1.0],
        ['Mexc', 'Kraken', 'USDT', 'TRC20', 'kraken_usdt_addr', 1.0],
        ['CoinEx', 'Mexc', 'USDT', 'TRC20', 'mexc_usdt_addr', 1.0],
        ['CoinEx', 'Kraken', 'USDT', 'TRC20', 'kraken_usdt_addr', 1.0],
        ['Kraken', 'Mexc', 'USDT', 'TRC20', 'key_usdt_mexc', 1.0],
        ['Kraken', 'CoinEx', 'USDT', 'TRC20', 'key_usdt_coinex', 1.0],
    ];

    foreach ($routes as [$from, $to, $asset, $networkCode, $address, $fee]) {
        $wallet = ExchangeWallet::query()->updateOrCreate(
            ['exchange' => $to, 'asset' => $asset, 'network_code' => $networkCode],
            ['address' => $address, 'memo' => null, 'is_active' => true]
        );

        ExchangeNetwork::query()->updateOrCreate(
            ['exchange' => $from, 'asset' => $asset, 'network_code' => $networkCode],
            ['network_id' => $networkCode, 'network_name' => $networkCode, 'fee' => $fee, 'min_amount' => 0, 'max_amount' => 0, 'deposit_enabled' => true, 'withdraw_enabled' => true]
        );

        TransferRoute::query()->updateOrCreate(
            ['from_exchange' => $from, 'to_exchange' => $to, 'asset' => $asset, 'network_code' => $networkCode],
            ['wallet_id' => $wallet->id, 'fee' => $fee, 'is_active' => true]
        );
    }
}

function mockExchanges(array $mexcBalances, array $coinexBalances, array $krakenBalances, float $usdtUsdRate = 1.0): void
{
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    $mexcMock->allows('getName')->andReturn('Mexc');
    $coinexMock->allows('getName')->andReturn('CoinEx');
    $krakenMock->allows('getName')->andReturn('Kraken');
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
