<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use App\Models\ArbitrageOpportunity;

$krakenUsdtBook = [
    'bids' => [['price' => 0.9995, 'amount' => 1000.0]],
    'asks' => [['price' => 1.0005, 'amount' => 1000.0]],
];

$emptyBook = ['bids' => [], 'asks' => []];

it('outputs no-opportunity message when no profitable spread exists', function () use ($krakenUsdtBook, $emptyBook) {
    $this->mock(Mexc::class)
        ->shouldReceive('getName')->andReturn('Mexc')
        ->shouldReceive('getTxFee')->andReturn(0.0005)
        ->shouldReceive('getOrderBook')->with('pep_usdt')->andReturn($emptyBook);

    $this->mock(CoinEx::class)
        ->shouldReceive('getName')->andReturn('CoinEx')
        ->shouldReceive('getTxFee')->andReturn(0.002)
        ->shouldReceive('getOrderBook')->with('pep_usdt')->andReturn($emptyBook);

    $this->mock(Kraken::class)
        ->shouldReceive('getName')->andReturn('Kraken')
        ->shouldReceive('getTxFee')->andReturn(0.0026)
        ->shouldReceive('getOrderBook')->with('pep_usd')->andReturn($emptyBook)
        ->shouldReceive('getOrderBook')->with('usdt_usd')->andReturn($krakenUsdtBook);

    $this->artisan('arbitrage:find', ['--once' => true])
        ->expectsOutputToContain('No opportunities above')
        ->assertSuccessful();

    expect(ArbitrageOpportunity::count())->toBe(0);
});

it('persists opportunity and outputs details when a profitable spread is found', function () use ($krakenUsdtBook, $emptyBook) {
    $mexcBook = [
        'bids' => [['price' => 0.0015, 'amount' => 1_000_000.0]],
        'asks' => [['price' => 0.001, 'amount' => 1_000_000.0]],
    ];

    $coinexBook = [
        'bids' => [['price' => 0.002, 'amount' => 1_000_000.0]],
        'asks' => [['price' => 0.003, 'amount' => 1_000_000.0]],
    ];

    $this->mock(Mexc::class)
        ->shouldReceive('getName')->andReturn('Mexc')
        ->shouldReceive('getTxFee')->andReturn(0.0005)
        ->shouldReceive('getOrderBook')->with('pep_usdt')->andReturn($mexcBook);

    $this->mock(CoinEx::class)
        ->shouldReceive('getName')->andReturn('CoinEx')
        ->shouldReceive('getTxFee')->andReturn(0.002)
        ->shouldReceive('getOrderBook')->with('pep_usdt')->andReturn($coinexBook);

    $this->mock(Kraken::class)
        ->shouldReceive('getName')->andReturn('Kraken')
        ->shouldReceive('getTxFee')->andReturn(0.0026)
        ->shouldReceive('getOrderBook')->with('pep_usd')->andReturn($emptyBook)
        ->shouldReceive('getOrderBook')->with('usdt_usd')->andReturn($krakenUsdtBook);

    $this->artisan('arbitrage:find', ['--once' => true])
        ->expectsOutputToContain('OPPORTUNITY Mexc → CoinEx')
        ->assertSuccessful();

    expect(ArbitrageOpportunity::count())->toBe(1);

    $opportunity = ArbitrageOpportunity::first();
    expect($opportunity->buy_exchange)->toBe('Mexc')
        ->and($opportunity->sell_exchange)->toBe('CoinEx')
        ->and($opportunity->profit_ratio)->toBeGreaterThan(0.003);
});

it('logs a warning and continues when a poll throws', function () use ($emptyBook) {
    $this->mock(Mexc::class)
        ->shouldReceive('getName')->andReturn('Mexc')
        ->shouldReceive('getTxFee')->andReturn(0.0005)
        ->shouldReceive('getOrderBook')->andThrow(new RuntimeException('API timeout'));

    $this->mock(CoinEx::class)
        ->shouldReceive('getName')->andReturn('CoinEx')
        ->shouldReceive('getTxFee')->andReturn(0.002)
        ->shouldReceive('getOrderBook')->andReturn($emptyBook);

    $this->mock(Kraken::class)
        ->shouldReceive('getName')->andReturn('Kraken')
        ->shouldReceive('getTxFee')->andReturn(0.0026)
        ->shouldReceive('getOrderBook')->andReturn($emptyBook);

    $this->artisan('arbitrage:find', ['--once' => true])
        ->expectsOutputToContain('Poll error: API timeout')
        ->assertSuccessful();

    expect(ArbitrageOpportunity::count())->toBe(0);
});

it('does not persist when spread is below --min-profit threshold', function () use ($krakenUsdtBook, $emptyBook) {
    $mexcBook = [
        'bids' => [['price' => 0.0012, 'amount' => 1_000_000.0]],
        'asks' => [['price' => 0.001, 'amount' => 1_000_000.0]],
    ];

    $coinexBook = [
        'bids' => [['price' => 0.0011, 'amount' => 1_000_000.0]],
        'asks' => [['price' => 0.0015, 'amount' => 1_000_000.0]],
    ];

    $this->mock(Mexc::class)
        ->shouldReceive('getName')->andReturn('Mexc')
        ->shouldReceive('getTxFee')->andReturn(0.0005)
        ->shouldReceive('getOrderBook')->with('pep_usdt')->andReturn($mexcBook);

    $this->mock(CoinEx::class)
        ->shouldReceive('getName')->andReturn('CoinEx')
        ->shouldReceive('getTxFee')->andReturn(0.002)
        ->shouldReceive('getOrderBook')->with('pep_usdt')->andReturn($coinexBook);

    $this->mock(Kraken::class)
        ->shouldReceive('getName')->andReturn('Kraken')
        ->shouldReceive('getTxFee')->andReturn(0.0026)
        ->shouldReceive('getOrderBook')->with('pep_usd')->andReturn($emptyBook)
        ->shouldReceive('getOrderBook')->with('usdt_usd')->andReturn($krakenUsdtBook);

    $this->artisan('arbitrage:find', ['--once' => true, '--min-profit' => '0.99'])
        ->expectsOutputToContain('No opportunities above')
        ->assertSuccessful();

    expect(ArbitrageOpportunity::count())->toBe(0);
});
