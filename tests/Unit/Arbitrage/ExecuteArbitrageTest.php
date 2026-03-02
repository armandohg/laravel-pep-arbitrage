<?php

use App\Arbitrage\ExecuteArbitrage;
use App\Arbitrage\ValueObjects\OpportunityData;
use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

function makeOpportunity(string $buy = 'Mexc', string $sell = 'CoinEx'): OpportunityData
{
    return new OpportunityData(
        buyExchange: $buy,
        sellExchange: $sell,
        amount: 100_000.0,
        avgBuyPrice: 0.001,
        avgSellPrice: 0.002,
        totalBuyCost: 100.0,
        totalSellRevenue: 200.0,
        profit: 100.0,
        profitRatio: 0.99,
        profitLevel: 'Extreme',
        buyLevels: [['price' => 0.001, 'amount' => 100_000.0]],
        sellLevels: [['price' => 0.002, 'amount' => 100_000.0]],
    );
}

it('returns success result when both orders are placed', function () {
    Config::set('arbitrage.order_type', 'limit');

    $mexc = Mockery::mock(Mexc::class);
    $mexc->shouldReceive('getName')->andReturn('Mexc');
    $mexc->shouldReceive('placeOrder')
        ->with('pep_usdt', 'buy', 100_000.0, 'limit', 0.001)
        ->andReturn(['orderId' => 'buy-123']);

    $coinex = Mockery::mock(CoinEx::class);
    $coinex->shouldReceive('getName')->andReturn('CoinEx');
    $coinex->shouldReceive('placeOrder')
        ->with('pep_usdt', 'sell', 100_000.0, 'limit', 0.002)
        ->andReturn(['orderId' => 'sell-456']);

    $kraken = Mockery::mock(Kraken::class);
    $kraken->shouldReceive('getName')->andReturn('Kraken');

    $service = new ExecuteArbitrage($mexc, $coinex, $kraken);
    $result = $service->execute(makeOpportunity(), 1.0);

    expect($result->success)->toBeTrue()
        ->and($result->buyOrderId)->toBe('buy-123')
        ->and($result->sellOrderId)->toBe('sell-456')
        ->and($result->failedSide)->toBeNull()
        ->and($result->error)->toBeNull();
});

it('returns failed result with failedSide=buy when buy order throws', function () {
    Config::set('arbitrage.order_type', 'limit');

    $mexc = Mockery::mock(Mexc::class);
    $mexc->shouldReceive('getName')->andReturn('Mexc');
    $mexc->shouldReceive('placeOrder')->andThrow(new RuntimeException('API error'));

    $coinex = Mockery::mock(CoinEx::class);
    $coinex->shouldReceive('getName')->andReturn('CoinEx');
    $coinex->shouldNotReceive('placeOrder');

    $kraken = Mockery::mock(Kraken::class);
    $kraken->shouldReceive('getName')->andReturn('Kraken');

    $service = new ExecuteArbitrage($mexc, $coinex, $kraken);
    $result = $service->execute(makeOpportunity(), 1.0);

    expect($result->success)->toBeFalse()
        ->and($result->failedSide)->toBe('buy')
        ->and($result->buyOrderId)->toBeNull()
        ->and($result->error)->toBe('API error');
});

it('logs critical and returns partial result when sell order fails after buy succeeds', function () {
    Config::set('arbitrage.order_type', 'limit');
    Log::spy();

    $mexc = Mockery::mock(Mexc::class);
    $mexc->shouldReceive('getName')->andReturn('Mexc');
    $mexc->shouldReceive('placeOrder')->andReturn(['orderId' => 'buy-999']);

    $coinex = Mockery::mock(CoinEx::class);
    $coinex->shouldReceive('getName')->andReturn('CoinEx');
    $coinex->shouldReceive('placeOrder')->andThrow(new RuntimeException('sell timeout'));

    $kraken = Mockery::mock(Kraken::class);
    $kraken->shouldReceive('getName')->andReturn('Kraken');

    $service = new ExecuteArbitrage($mexc, $coinex, $kraken);
    $result = $service->execute(makeOpportunity(), 1.0);

    expect($result->success)->toBeFalse()
        ->and($result->failedSide)->toBe('sell')
        ->and($result->buyOrderId)->toBe('buy-999')
        ->and($result->sellOrderId)->toBeNull()
        ->and($result->error)->toBe('sell timeout');

    Log::shouldHaveReceived('critical')->once();
});

it('converts price to USD when sell exchange is Kraken', function () {
    Config::set('arbitrage.order_type', 'limit');

    $mexc = Mockery::mock(Mexc::class);
    $mexc->shouldReceive('getName')->andReturn('Mexc');
    $mexc->shouldReceive('placeOrder')
        ->with('pep_usdt', 'buy', 100_000.0, 'limit', 0.001)
        ->andReturn(['orderId' => 'buy-abc']);

    $kraken = Mockery::mock(Kraken::class);
    $kraken->shouldReceive('getName')->andReturn('Kraken');
    // Sell price = 0.002 USDT * 1.05 USD/USDT = 0.0021 USD
    $kraken->shouldReceive('placeOrder')
        ->with('pep_usdt', 'sell', 100_000.0, 'limit', Mockery::on(fn ($p) => abs($p - 0.0021) < 0.000001))
        ->andReturn(['orderId' => 'sell-xyz']);

    $coinex = Mockery::mock(CoinEx::class);
    $coinex->shouldReceive('getName')->andReturn('CoinEx');

    $service = new ExecuteArbitrage($mexc, $coinex, $kraken);
    $result = $service->execute(makeOpportunity('Mexc', 'Kraken'), 1.05);

    expect($result->success)->toBeTrue()
        ->and($result->buyOrderId)->toBe('buy-abc')
        ->and($result->sellOrderId)->toBe('sell-xyz');
});

it('uses market orders when order_type is market', function () {
    Config::set('arbitrage.order_type', 'market');

    $mexc = Mockery::mock(Mexc::class);
    $mexc->shouldReceive('getName')->andReturn('Mexc');
    $mexc->shouldReceive('placeOrder')
        ->with('pep_usdt', 'buy', 100_000.0, 'market', null)
        ->andReturn(['orderId' => 'buy-mkt']);

    $coinex = Mockery::mock(CoinEx::class);
    $coinex->shouldReceive('getName')->andReturn('CoinEx');
    $coinex->shouldReceive('placeOrder')
        ->with('pep_usdt', 'sell', 100_000.0, 'market', null)
        ->andReturn(['orderId' => 'sell-mkt']);

    $kraken = Mockery::mock(Kraken::class);
    $kraken->shouldReceive('getName')->andReturn('Kraken');

    $service = new ExecuteArbitrage($mexc, $coinex, $kraken);
    $result = $service->execute(makeOpportunity(), 1.0);

    expect($result->success)->toBeTrue();
});
