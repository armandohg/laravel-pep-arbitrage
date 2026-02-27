<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use App\Rebalance\NetworkRouter;
use App\Rebalance\RebalanceService;

beforeEach(function () {
    config()->set('exchanges.networks', [
        'PEP' => ['fee' => 1.0, 'currency' => 'PEP', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
        'TRC20' => ['fee' => 1.0, 'currency' => 'USDT', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
        'ERC20' => ['fee' => 10.0, 'currency' => 'USDT', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
    ]);
});

function makeService(array $mexcBalances, array $coinexBalances, array $krakenBalances, float $usdtUsdRate = 1.0): RebalanceService
{
    $mexc = Mockery::mock(Mexc::class);
    $coinex = Mockery::mock(CoinEx::class);
    $kraken = Mockery::mock(Kraken::class);

    $mexc->allows('getBalances')->andReturn($mexcBalances);
    $coinex->allows('getBalances')->andReturn($coinexBalances);
    $kraken->allows('getBalances')->andReturn($krakenBalances);
    $kraken->allows('getOrderBook')->with('usdt_usd')->andReturn([
        'asks' => [[$usdtUsdRate, 1000]],
        'bids' => [[$usdtUsdRate - 0.001, 1000]],
    ]);

    return new RebalanceService($mexc, $coinex, $kraken, new NetworkRouter);
}

test('returns isBalanced true when all exchanges are within tolerance', function () {
    $service = makeService(
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 200.0]],
    );

    $plan = $service->plan(0.10);

    expect($plan->isBalanced)->toBeTrue()
        ->and($plan->transfers)->toBeEmpty();
});

test('generates PEP and USDT transfers when imbalanced', function () {
    $service = makeService(
        ['PEP' => ['available' => 4_000_000], 'USDT' => ['available' => 400.0]],
        ['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 100.0]],
        ['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 100.0]],
    );

    $plan = $service->plan(0.10);

    expect($plan->isBalanced)->toBeFalse()
        ->and($plan->transfers)->not->toBeEmpty();

    $currencies = array_map(fn ($t) => $t->currency, $plan->transfers);
    expect($currencies)->toContain('PEP')
        ->and($currencies)->toContain('USDT');
});

test('adds krakenStep when Kraken sends USDT', function () {
    $service = makeService(
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 100.0]],
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 100.0]],
        ['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 400.0]],
    );

    $plan = $service->plan(0.10);

    $krakenTransfer = collect($plan->transfers)
        ->first(fn ($t) => $t->fromExchange === 'Kraken' && $t->currency === 'USDT');

    expect($krakenTransfer)->not->toBeNull()
        ->and($krakenTransfer->krakenStep)->toContain('buy');
});

test('adds krakenStep when Kraken receives USDT', function () {
    $service = makeService(
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 400.0]],
        ['PEP' => ['available' => 2_000_000], 'USDT' => ['available' => 100.0]],
        ['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 100.0]],
    );

    $plan = $service->plan(0.10);

    $krakenTransfer = collect($plan->transfers)
        ->first(fn ($t) => $t->toExchange === 'Kraken' && $t->currency === 'USDT');

    expect($krakenTransfer)->not->toBeNull()
        ->and($krakenTransfer->krakenStep)->toContain('sell');
});
