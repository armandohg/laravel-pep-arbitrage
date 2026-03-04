<?php

use App\Exchanges\Kraken;
use App\Models\RebalanceTransfer;

beforeEach(function () {
    $this->kraken = Mockery::mock(Kraken::class);
    $this->kraken->allows('getName')->andReturn('Kraken');
    app()->instance(Kraken::class, $this->kraken);
});

function makeUsdtKrakenTransfer(array $overrides = []): RebalanceTransfer
{
    return RebalanceTransfer::create(array_merge([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'Kraken',
        'currency' => 'USDT',
        'amount' => 100.0,
        'network' => 'TRC20',
        'expires_at' => now()->addHours(2),
        'settled_at' => null,
    ], $overrides));
}

test('exits silently with no API calls when no pending transfers exist', function () {
    $this->kraken->shouldNotReceive('getBalances');
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:settle-kraken')->assertSuccessful();
});

test('exits silently when all transfers are already settled', function () {
    makeUsdtKrakenTransfer(['settled_at' => now()->subMinutes(10)]);

    $this->kraken->shouldNotReceive('getBalances');
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:settle-kraken')->assertSuccessful();
});

test('exits silently when all transfers are expired', function () {
    makeUsdtKrakenTransfer(['expires_at' => now()->subMinute()]);

    $this->kraken->shouldNotReceive('getBalances');
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:settle-kraken')->assertSuccessful();
});

test('returns success without selling when USDT balance is below 1', function () {
    makeUsdtKrakenTransfer();

    $this->kraken->allows('getBalances')->andReturn(['USDT' => ['available' => 0.5]]);
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:settle-kraken')->assertSuccessful();
});

test('returns success without selling when USDT key is absent from balances', function () {
    makeUsdtKrakenTransfer();

    $this->kraken->allows('getBalances')->andReturn(['USD' => ['available' => 500.0]]);
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:settle-kraken')->assertSuccessful();
});

test('sells USDT with amount rounded to 2 decimals', function () {
    makeUsdtKrakenTransfer();

    $this->kraken->allows('getBalances')->andReturn(['USDT' => ['available' => 123.456789]]);
    $this->kraken->expects('sellUsdt')->with(123.46)->once()->andReturn(['result' => []]);

    $this->artisan('exchanges:settle-kraken')->assertSuccessful();
});

test('marks all pending unsettled transfers as settled after successful sell', function () {
    $t1 = makeUsdtKrakenTransfer(['amount' => 50.0]);
    $t2 = makeUsdtKrakenTransfer(['amount' => 50.0]);

    $this->kraken->allows('getBalances')->andReturn(['USDT' => ['available' => 100.0]]);
    $this->kraken->allows('sellUsdt')->andReturn(['result' => []]);

    $this->artisan('exchanges:settle-kraken')->assertSuccessful();

    expect($t1->fresh()->settled_at)->not->toBeNull()
        ->and($t2->fresh()->settled_at)->not->toBeNull();
});

test('does not mark transfers settled when sellUsdt throws', function () {
    $transfer = makeUsdtKrakenTransfer();

    $this->kraken->allows('getBalances')->andReturn(['USDT' => ['available' => 100.0]]);
    $this->kraken->allows('sellUsdt')->andThrow(new RuntimeException('Kraken error'));

    $this->artisan('exchanges:settle-kraken')->assertFailed();

    expect($transfer->fresh()->settled_at)->toBeNull();
});

test('returns failure when getBalances throws', function () {
    makeUsdtKrakenTransfer();

    $this->kraken->allows('getBalances')->andThrow(new RuntimeException('API down'));

    $this->artisan('exchanges:settle-kraken')->assertFailed();
});

test('ignores non-Kraken USDT transfers', function () {
    RebalanceTransfer::create([
        'from_exchange' => 'Kraken',
        'to_exchange' => 'Mexc',
        'currency' => 'USDT',
        'amount' => 100.0,
        'network' => 'TRC20',
        'expires_at' => now()->addHours(2),
        'settled_at' => null,
    ]);

    $this->kraken->shouldNotReceive('getBalances');
    $this->kraken->shouldNotReceive('sellUsdt');

    $this->artisan('exchanges:settle-kraken')->assertSuccessful();
});

test('outputs the sold amount on success', function () {
    makeUsdtKrakenTransfer(['amount' => 50.0]);
    makeUsdtKrakenTransfer(['amount' => 50.0]);

    $this->kraken->allows('getBalances')->andReturn(['USDT' => ['available' => 100.0]]);
    $this->kraken->allows('sellUsdt')->andReturn(['result' => []]);

    $this->artisan('exchanges:settle-kraken')
        ->assertSuccessful()
        ->expectsOutputToContain('100');

    expect(RebalanceTransfer::whereNotNull('settled_at')->count())->toBe(2);
});
