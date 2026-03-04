<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use App\Models\ExchangeTransferCooldown;
use App\Models\RebalanceTransfer;
use App\Rebalance\Transfer;

// ── ExchangeTransferCooldown ──────────────────────────────────────────────────

test('minutesFor returns cooldown from database for exchange and currency', function () {
    ExchangeTransferCooldown::create(['exchange' => 'Kraken', 'currency' => 'PEP', 'cooldown_minutes' => 60]);
    ExchangeTransferCooldown::create(['exchange' => 'Kraken', 'currency' => 'USDT', 'cooldown_minutes' => 30]);

    expect(ExchangeTransferCooldown::minutesFor('Kraken', 'PEP'))->toBe(60)
        ->and(ExchangeTransferCooldown::minutesFor('Kraken', 'USDT'))->toBe(30);
});

test('minutesFor returns 60 as default when no record exists', function () {
    expect(ExchangeTransferCooldown::minutesFor('UnknownExchange', 'PEP'))->toBe(60);
});

// ── RebalanceTransfer ─────────────────────────────────────────────────────────

test('hasPendingTo returns true when a non-expired transfer exists', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'Kraken',
        'currency' => 'PEP',
        'amount' => 1_000_000,
        'network' => 'PEP',
        'expires_at' => now()->addMinutes(30),
    ]);

    expect(RebalanceTransfer::hasPendingTo('Kraken', 'PEP'))->toBeTrue();
});

test('hasPendingTo returns false when transfer is settled', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'Kraken',
        'currency' => 'PEP',
        'amount' => 1_000_000,
        'network' => 'PEP',
        'expires_at' => now()->addMinutes(30),
        'settled_at' => now()->subMinutes(5),
    ]);

    expect(RebalanceTransfer::hasPendingTo('Kraken', 'PEP'))->toBeFalse();
});

test('hasPendingTo returns true when transfer is unsettled and within expiry', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'Kraken',
        'currency' => 'USDT',
        'amount' => 100,
        'network' => 'TRC20',
        'expires_at' => now()->addMinutes(30),
        'settled_at' => null,
    ]);

    expect(RebalanceTransfer::hasPendingTo('Kraken', 'USDT'))->toBeTrue();
});

test('hasPendingTo returns false when transfer is expired', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'Kraken',
        'currency' => 'PEP',
        'amount' => 1_000_000,
        'network' => 'PEP',
        'expires_at' => now()->subMinute(),
    ]);

    expect(RebalanceTransfer::hasPendingTo('Kraken', 'PEP'))->toBeFalse();
});

test('hasPendingTo returns false when no transfer exists', function () {
    expect(RebalanceTransfer::hasPendingTo('Kraken', 'PEP'))->toBeFalse();
});

test('hasPendingTo is scoped by currency', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'Kraken',
        'currency' => 'USDT',
        'amount' => 100,
        'network' => 'TRC20',
        'expires_at' => now()->addMinutes(30),
    ]);

    expect(RebalanceTransfer::hasPendingTo('Kraken', 'PEP'))->toBeFalse();
    expect(RebalanceTransfer::hasPendingTo('Kraken', 'USDT'))->toBeTrue();
});

test('record creates transfer with correct expires_at', function () {
    $transfer = new Transfer(
        fromExchange: 'Mexc',
        toExchange: 'CoinEx',
        currency: 'PEP',
        amount: 500_000,
        network: 'PEP',
        networkId: 'PEP',
        address: 'coinex_pep_addr',
        networkFee: 1.0,
    );

    $expiresAt = now()->addMinutes(20);
    RebalanceTransfer::record($transfer, $expiresAt);

    $saved = RebalanceTransfer::first();
    expect($saved->from_exchange)->toBe('Mexc')
        ->and($saved->to_exchange)->toBe('CoinEx')
        ->and($saved->currency)->toBe('PEP')
        ->and($saved->amount)->toBe(500_000.0)
        ->and($saved->network)->toBe('PEP')
        ->and($saved->expires_at->timestamp)->toBe($expiresAt->timestamp);
});

// ── RebalanceService::execute() ───────────────────────────────────────────────

test('--execute skips transfer when pending transfer already exists for destination', function () {
    seedTransferRoutes();

    ExchangeTransferCooldown::create(['exchange' => 'CoinEx', 'currency' => 'PEP', 'cooldown_minutes' => 20]);

    // Simulate a pending transfer already in flight to CoinEx PEP
    RebalanceTransfer::create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'CoinEx',
        'currency' => 'PEP',
        'amount' => 1_000_000,
        'network' => 'PEP',
        'expires_at' => now()->addMinutes(10),
    ]);

    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);
    $krakenMock = Mockery::mock(Kraken::class);

    $mexcMock->allows('getName')->andReturn('Mexc');
    $coinexMock->allows('getName')->andReturn('CoinEx');
    $krakenMock->allows('getName')->andReturn('Kraken');
    // Balances generate exactly one transfer: Mexc→CoinEx PEP (Kraken is at target already)
    $mexcMock->allows('getBalances')->andReturn(['PEP' => ['available' => 3_000_000], 'USDT' => ['available' => 200.0]]);
    $coinexMock->allows('getBalances')->andReturn(['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 200.0]]);
    $krakenMock->allows('getBalances')->andReturn(['PEP' => ['available' => 2_000_000], 'USD' => ['available' => 200.0]]);
    $krakenMock->allows('getOrderBook')->with('usdt_usd')->andReturn(['asks' => [[1.0, 1000]], 'bids' => [[0.999, 1000]]]);

    // The only planned transfer (Mexc→CoinEx) is blocked — no withdraw should fire
    $mexcMock->shouldNotReceive('withdraw');
    $coinexMock->shouldNotReceive('withdraw');
    $krakenMock->shouldNotReceive('withdraw');

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
    app()->instance(Kraken::class, $krakenMock);

    $this->artisan('exchanges:rebalance --execute')->assertSuccessful();
});

test('--execute records transfer with correct expires_at after successful withdraw', function () {
    seedTransferRoutes();

    ExchangeTransferCooldown::create(['exchange' => 'CoinEx', 'currency' => 'PEP', 'cooldown_minutes' => 20]);

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
    $mexcMock->allows('withdraw')->andReturn(['success' => true]);
    $coinexMock->allows('withdraw')->andReturn(['success' => true]);
    $krakenMock->allows('withdraw')->andReturn(['success' => true]);
    $krakenMock->allows('buyUsdt')->andReturn(['success' => true]);

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
    app()->instance(Kraken::class, $krakenMock);

    $this->artisan('exchanges:rebalance --execute')->assertSuccessful();

    $coinexTransfer = RebalanceTransfer::where('to_exchange', 'CoinEx')
        ->where('currency', 'PEP')
        ->first();

    expect($coinexTransfer)->not->toBeNull()
        ->and($coinexTransfer->expires_at->greaterThan(now()->addMinutes(19)))->toBeTrue();
});

test('dry-run shows warning when pending transfer exists for a planned destination', function () {
    seedTransferRoutes();

    RebalanceTransfer::create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'CoinEx',
        'currency' => 'PEP',
        'amount' => 1_000_000,
        'network' => 'PEP',
        'expires_at' => now()->addMinutes(15),
    ]);

    mockExchanges(
        ['PEP' => ['available' => 4_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 1_000_000], 'USDT' => ['available' => 200.0]],
        ['PEP' => ['available' => 1_000_000], 'USD' => ['available' => 200.0]],
    );

    $this->artisan('exchanges:rebalance')
        ->assertSuccessful()
        ->expectsOutputToContain('Pending transfer in progress');
});
