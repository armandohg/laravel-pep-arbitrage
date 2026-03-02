<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use App\Models\BalanceSnapshot;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->mexc = Mockery::mock(Mexc::class);
    $this->coinex = Mockery::mock(CoinEx::class);
    $this->kraken = Mockery::mock(Kraken::class);

    $this->mexc->allows('getName')->andReturn('Mexc');
    $this->coinex->allows('getName')->andReturn('CoinEx');
    $this->kraken->allows('getName')->andReturn('Kraken');

    app()->instance(Mexc::class, $this->mexc);
    app()->instance(CoinEx::class, $this->coinex);
    app()->instance(Kraken::class, $this->kraken);
});

test('records a snapshot with summed totals per currency', function () {
    $this->mexc->allows('getBalances')->andReturn([
        'PEP' => ['available' => 500_000.0],
        'USDT' => ['available' => 100.0],
    ]);
    $this->coinex->allows('getBalances')->andReturn([
        'PEP' => ['available' => 300_000.0],
        'USDT' => ['available' => 50.0],
    ]);
    $this->kraken->allows('getBalances')->andReturn([
        'USD' => ['available' => 200.0],
    ]);

    $this->artisan('exchanges:snapshot-balances')->assertSuccessful();

    expect(BalanceSnapshot::query()->where('currency', 'PEP')->count())->toBe(1)
        ->and(BalanceSnapshot::query()->where('currency', 'PEP')->value('total_available'))->toBeCloseTo(800_000.0)
        ->and(BalanceSnapshot::query()->where('currency', 'USDT')->value('total_available'))->toBeCloseTo(150.0)
        ->and(BalanceSnapshot::query()->where('currency', 'USD')->value('total_available'))->toBeCloseTo(200.0);
});

test('uses the same snapped_at timestamp for all currencies in a run', function () {
    Carbon::setTestNow('2025-01-15 12:00:00');

    $this->mexc->allows('getBalances')->andReturn(['PEP' => ['available' => 1.0]]);
    $this->coinex->allows('getBalances')->andReturn(['USDT' => ['available' => 2.0]]);
    $this->kraken->allows('getBalances')->andReturn(['USD' => ['available' => 3.0]]);

    $this->artisan('exchanges:snapshot-balances')->assertSuccessful();

    $snappedAts = BalanceSnapshot::query()->pluck('snapped_at')->map->toDateTimeString()->unique();

    expect($snappedAts)->toHaveCount(1)
        ->and($snappedAts->first())->toBe('2025-01-15 12:00:00');
});

test('continues when one exchange fails and records remaining balances', function () {
    $this->mexc->allows('getBalances')->andReturn(['PEP' => ['available' => 500_000.0]]);
    $this->coinex->allows('getBalances')->andThrow(new RuntimeException('API error'));
    $this->kraken->allows('getBalances')->andReturn(['USD' => ['available' => 100.0]]);

    $this->artisan('exchanges:snapshot-balances')->assertSuccessful();

    expect(BalanceSnapshot::query()->count())->toBe(2);
});

test('returns failure when no exchange returns balances', function () {
    $this->mexc->allows('getBalances')->andThrow(new RuntimeException('down'));
    $this->coinex->allows('getBalances')->andThrow(new RuntimeException('down'));
    $this->kraken->allows('getBalances')->andThrow(new RuntimeException('down'));

    $this->artisan('exchanges:snapshot-balances')->assertFailed();

    expect(BalanceSnapshot::query()->count())->toBe(0);
});
