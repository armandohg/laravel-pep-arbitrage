<?php

use App\Exchanges\Kraken;

beforeEach(function () {
    $this->kraken = Mockery::mock(Kraken::class);
    $this->kraken->allows('getName')->andReturn('Kraken');
    app()->instance(Kraken::class, $this->kraken);

    // Avoid real sleeps in tests
    config(['arbitrage.kraken_usdt_auto_sell_wait_seconds' => 0]);
});

test('exits silently when USDT balance is below threshold on first check', function () {
    $this->kraken->allows('getBalances')->once()->andReturn(['USDT' => ['available' => 5.0]]);
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertSuccessful();
});

test('exits silently when USDT key is absent from balances on first check', function () {
    $this->kraken->allows('getBalances')->once()->andReturn(['USD' => ['available' => 500.0]]);
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertSuccessful();
});

test('exits silently when USDT drops below threshold after the wait', function () {
    $this->kraken->allows('getBalances')->twice()->andReturn(
        ['USDT' => ['available' => 20.0]],
        ['USDT' => ['available' => 2.0]],
    );
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertSuccessful();
});

test('sells USDT when balance remains above threshold after the wait', function () {
    $this->kraken->allows('getBalances')->twice()->andReturn(
        ['USDT' => ['available' => 50.0]],
        ['USDT' => ['available' => 50.0]],
    );
    $this->kraken->expects('sellUsdt')->with(50.0)->once()->andReturn(['result' => []]);

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertSuccessful();
});

test('sells amount rounded to 2 decimals', function () {
    $this->kraken->allows('getBalances')->twice()->andReturn(
        ['USDT' => ['available' => 123.456789]],
        ['USDT' => ['available' => 123.456789]],
    );
    $this->kraken->expects('sellUsdt')->with(123.46)->once()->andReturn(['result' => []]);

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertSuccessful();
});

test('respects custom threshold from config', function () {
    config(['arbitrage.kraken_usdt_auto_sell_threshold' => 100.0]);

    $this->kraken->allows('getBalances')->once()->andReturn(['USDT' => ['available' => 50.0]]);
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertSuccessful();
});

test('returns failure when first getBalances throws', function () {
    $this->kraken->allows('getBalances')->andThrow(new RuntimeException('API down'));
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertFailed();
});

test('returns failure when second getBalances throws', function () {
    $this->kraken->allows('getBalances')->andReturn(
        ['USDT' => ['available' => 50.0]],
    )->once()->ordered();

    $this->kraken->allows('getBalances')->andThrow(new RuntimeException('API down'))->once()->ordered();
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertFailed();
});

test('returns failure when sellUsdt throws', function () {
    $this->kraken->allows('getBalances')->twice()->andReturn(['USDT' => ['available' => 50.0]]);
    $this->kraken->allows('sellUsdt')->andThrow(new RuntimeException('Order rejected'));

    $this->artisan('exchanges:auto-sell-usdt-kraken')->assertFailed();
});

test('outputs the sold amount on success', function () {
    $this->kraken->allows('getBalances')->twice()->andReturn(['USDT' => ['available' => 75.5]]);
    $this->kraken->allows('sellUsdt')->andReturn(['result' => []]);

    $this->artisan('exchanges:auto-sell-usdt-kraken')
        ->assertSuccessful()
        ->expectsOutputToContain('75.5');
});
