<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)->in('Unit/Exchanges');

pest()->extend(Tests\TestCase::class)->in('Unit/Arbitrage');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Rebalance');

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Unit/Models');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toBeCloseTo', function (float $expected, int $precision = 8): \Pest\Expectation {
    $diff = abs($this->value - $expected);
    $tolerance = 10 ** -$precision;
    expect($diff)->toBeLessThan($tolerance);

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

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
        $wallet = \App\Models\ExchangeWallet::query()->updateOrCreate(
            ['exchange' => $to, 'asset' => $asset, 'network_code' => $networkCode],
            ['address' => $address, 'memo' => null, 'is_active' => true]
        );

        \App\Models\ExchangeNetwork::query()->updateOrCreate(
            ['exchange' => $from, 'asset' => $asset, 'network_code' => $networkCode],
            ['network_id' => $networkCode, 'network_name' => $networkCode, 'fee' => $fee, 'min_amount' => 0, 'max_amount' => 0, 'deposit_enabled' => true, 'withdraw_enabled' => true]
        );

        \App\Models\TransferRoute::query()->updateOrCreate(
            ['from_exchange' => $from, 'to_exchange' => $to, 'asset' => $asset, 'network_code' => $networkCode],
            ['wallet_id' => $wallet->id, 'fee' => $fee, 'is_active' => true]
        );
    }
}

function mockExchanges(array $mexcBalances, array $coinexBalances, array $krakenBalances, float $usdtUsdRate = 1.0): void
{
    $mexcMock = Mockery::mock(\App\Exchanges\Mexc::class);
    $coinexMock = Mockery::mock(\App\Exchanges\CoinEx::class);
    $krakenMock = Mockery::mock(\App\Exchanges\Kraken::class);

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

    app()->instance(\App\Exchanges\Mexc::class, $mexcMock);
    app()->instance(\App\Exchanges\CoinEx::class, $coinexMock);
    app()->instance(\App\Exchanges\Kraken::class, $krakenMock);
}
