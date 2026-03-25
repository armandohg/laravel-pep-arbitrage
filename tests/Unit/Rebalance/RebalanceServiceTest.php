<?php

use App\Exchanges\CoinEx;
use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use App\Models\ExchangeNetwork;
use App\Models\ExchangeReserve;
use App\Models\ExchangeWallet;
use App\Models\TransferRoute;
use App\Rebalance\RebalanceService;
use App\Rebalance\TransferRouteService;

beforeEach(function () {
    seedServiceRoutes();
});

function seedServiceRoutes(): void
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

function makeService(array $mexcBalances, array $coinexBalances, array $krakenBalances, float $usdtUsdRate = 1.0): RebalanceService
{
    $mexc = Mockery::mock(Mexc::class);
    $coinex = Mockery::mock(CoinEx::class);
    $kraken = Mockery::mock(Kraken::class);

    $mexc->allows('getName')->andReturn('Mexc');
    $coinex->allows('getName')->andReturn('CoinEx');
    $kraken->allows('getName')->andReturn('Kraken');
    $mexc->allows('getBalances')->andReturn($mexcBalances);
    $coinex->allows('getBalances')->andReturn($coinexBalances);
    $kraken->allows('getBalances')->andReturn($krakenBalances);
    $kraken->allows('getOrderBook')->with('usdt_usd')->andReturn([
        'asks' => [[$usdtUsdRate, 1000]],
        'bids' => [[$usdtUsdRate - 0.001, 1000]],
    ]);

    return new RebalanceService(new ExchangeRegistry($mexc, $coinex, $kraken), app(TransferRouteService::class));
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

test('transfer includes address from DB route', function () {
    $service = makeService(
        ['PEP' => ['available' => 4_000_000], 'USDT' => ['available' => 400.0]],
        ['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 100.0]],
        ['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 100.0]],
    );

    $plan = $service->plan(0.10);

    $pepTransfer = collect($plan->transfers)->first(fn ($t) => $t->currency === 'PEP');

    expect($pepTransfer)->not->toBeNull()
        ->and($pepTransfer->address)->not->toBeEmpty();
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

test('kraken target includes its PEP reserve when total exceeds total reserved', function () {
    // Total PEP = 9M, Kraken reserve = 4M → share = (9M - 4M) / 3 = 1.67M → Kraken target = 5.67M
    ExchangeReserve::create(['exchange' => 'Kraken', 'currency' => 'PEP', 'min_amount' => 4_000_000]);

    $service = makeService(
        ['PEP' => ['available' => 3_000_000], 'USDT' => ['available' => 300.0]],
        ['PEP' => ['available' => 3_000_000], 'USDT' => ['available' => 300.0]],
        ['PEP' => ['available' => 3_000_000], 'USD' => ['available' => 300.0]],
    );

    $plan = $service->plan(0.10);

    $krakenTarget = collect($plan->targets)->first(fn ($t) => $t->exchange === 'Kraken');

    expect($krakenTarget->pep)->toBeGreaterThan(4_000_000.0);
});

test('plan falls back to equal split when total PEP is less than total reserves', function () {
    // Total = 3M, reserves = 4M → fallback: each gets 1M
    ExchangeReserve::create(['exchange' => 'Kraken', 'currency' => 'PEP', 'min_amount' => 4_000_000]);

    $service = makeService(
        ['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 100.0]],
        ['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 100.0]],
        ['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 100.0]],
    );

    $plan = $service->plan(0.10);

    foreach ($plan->targets as $target) {
        expect($target->pep)->toBe(1_000_000.0);
    }
});
