<?php

use App\Exchanges\CoinEx;
use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Mexc;
use App\Models\RebalanceTransfer;

beforeEach(function () {
    $this->mexc = Mockery::mock(Mexc::class);
    $this->mexc->allows('getName')->andReturn('Mexc');

    $this->coinex = Mockery::mock(CoinEx::class);
    $this->coinex->allows('getName')->andReturn('CoinEx');

    app()->instance(Mexc::class, $this->mexc);
    app()->instance(CoinEx::class, $this->coinex);

    $registry = app(ExchangeRegistry::class);
    app()->instance(ExchangeRegistry::class, $registry);
});

function makeTransfer(array $overrides = []): RebalanceTransfer
{
    return RebalanceTransfer::create(array_merge([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'CoinEx',
        'currency' => 'USDT',
        'amount' => 100.0,
        'network' => 'TRC20',
        'withdrawal_id' => null,
        'withdrawal_status' => null,
        'expires_at' => now()->addHours(2),
        'settled_at' => null,
    ], $overrides));
}

test('exits silently when no unsettled transfers exist', function () {
    makeTransfer(['settled_at' => now()->subMinutes(5)]);

    $this->mexc->shouldNotReceive('getWithdrawalStatus');
    $this->coinex->shouldNotReceive('getBalances');

    $this->artisan('exchanges:track-transfers')
        ->assertSuccessful()
        ->expectsOutputToContain('No unsettled transfers');
});

test('marks expired unsettled transfer as failed', function () {
    $transfer = makeTransfer(['expires_at' => now()->subMinutes(5)]);

    $this->artisan('exchanges:track-transfers')->assertSuccessful();

    $transfer->refresh();
    expect($transfer->withdrawal_status)->toBe('failed')
        ->and($transfer->settled_at)->not->toBeNull();
});

test('polls withdrawal status and updates when withdrawal_id is set', function () {
    $transfer = makeTransfer(['withdrawal_id' => 'WD123', 'withdrawal_status' => 'pending']);

    $this->mexc->allows('getWithdrawalStatus')
        ->with('WD123')
        ->andReturn(['status' => 'processing', 'tx_hash' => null]);

    $this->coinex->allows('getBalances')->andReturn(['USDT' => ['available' => 0.0]]);

    $this->artisan('exchanges:track-transfers')->assertSuccessful();

    expect($transfer->fresh()->withdrawal_status)->toBe('processing');
});

test('updates tx_hash when returned from withdrawal status', function () {
    $transfer = makeTransfer(['withdrawal_id' => 'WD999', 'withdrawal_status' => 'processing']);

    $this->mexc->allows('getWithdrawalStatus')
        ->with('WD999')
        ->andReturn(['status' => 'completed', 'tx_hash' => '0xabc123']);

    $this->coinex->allows('getBalances')->andReturn(['USDT' => ['available' => 0.0]]);

    $this->artisan('exchanges:track-transfers')->assertSuccessful();

    expect($transfer->fresh()->tx_hash)->toBe('0xabc123');
});

test('does not poll source exchange when no withdrawal_id', function () {
    makeTransfer(['withdrawal_id' => null]);

    $this->mexc->shouldNotReceive('getWithdrawalStatus');
    $this->coinex->allows('getBalances')->andReturn(['USDT' => ['available' => 0.0]]);

    $this->artisan('exchanges:track-transfers')->assertSuccessful();
});

test('settles transfer when destination balance exceeds 95% of amount', function () {
    $transfer = makeTransfer(['withdrawal_id' => 'WD1', 'withdrawal_status' => 'completed', 'amount' => 100.0]);

    $this->mexc->allows('getWithdrawalStatus')
        ->andReturn(['status' => 'completed', 'tx_hash' => null]);

    $this->coinex->allows('getBalances')->andReturn(['USDT' => ['available' => 97.0]]);

    $this->artisan('exchanges:track-transfers')->assertSuccessful();

    $transfer->refresh();
    expect($transfer->settled_at)->not->toBeNull()
        ->and($transfer->deposit_confirmed_at)->not->toBeNull()
        ->and($transfer->withdrawal_status)->toBe('completed');
});

test('does not settle when destination balance is below 95% of amount', function () {
    $transfer = makeTransfer(['amount' => 100.0]);

    $this->coinex->allows('getBalances')->andReturn(['USDT' => ['available' => 50.0]]);

    $this->artisan('exchanges:track-transfers')->assertSuccessful();

    expect($transfer->fresh()->settled_at)->toBeNull()
        ->and($transfer->fresh()->deposit_confirmed_at)->toBeNull();
});

test('does not call source exchange when withdrawal_status is already completed', function () {
    makeTransfer(['withdrawal_id' => 'WD5', 'withdrawal_status' => 'completed']);

    $this->mexc->shouldNotReceive('getWithdrawalStatus');
    $this->coinex->allows('getBalances')->andReturn(['USDT' => ['available' => 20.0]]);

    $this->artisan('exchanges:track-transfers')->assertSuccessful();
});

test('stops processing transfer when withdrawal status is failed', function () {
    $transfer = makeTransfer(['withdrawal_id' => 'WD6', 'withdrawal_status' => 'pending']);

    $this->mexc->allows('getWithdrawalStatus')
        ->with('WD6')
        ->andReturn(['status' => 'failed', 'tx_hash' => null]);

    $this->coinex->shouldNotReceive('getBalances');

    $this->artisan('exchanges:track-transfers')->assertSuccessful();

    expect($transfer->fresh()->withdrawal_status)->toBe('failed');
});

test('handles getWithdrawalStatus exceptions gracefully', function () {
    $transfer = makeTransfer(['withdrawal_id' => 'WD7', 'withdrawal_status' => 'pending']);

    $this->mexc->allows('getWithdrawalStatus')
        ->andThrow(new RuntimeException('API error'));

    $this->coinex->allows('getBalances')->andReturn(['USDT' => ['available' => 0.0]]);

    $this->artisan('exchanges:track-transfers')->assertSuccessful();

    expect($transfer->fresh()->settled_at)->toBeNull();
});

test('handles getBalances exceptions gracefully', function () {
    $transfer = makeTransfer();

    $this->coinex->allows('getBalances')
        ->andThrow(new RuntimeException('Network timeout'));

    $this->artisan('exchanges:track-transfers')->assertSuccessful();

    expect($transfer->fresh()->settled_at)->toBeNull();
});
