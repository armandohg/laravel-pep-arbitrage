<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;

function makeMarket(string $base, string $quote, float $volume, float $change = 2.0, float $price = 1.0): array
{
    return [
        'symbol' => strtolower($base).'_'.strtolower($quote),
        'base' => $base,
        'quote_volume_24h' => $volume,
        'price_change_pct' => $change,
        'last_price' => $price,
    ];
}

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

test('displays intersection of pairs available on all 3 exchanges', function () {
    $this->mexc->allows('getAvailableMarkets')->andReturn([
        makeMarket('BTC', 'USDT', 500_000),
        makeMarket('ETH', 'USDT', 300_000),
        makeMarket('DOGE', 'USDT', 100_000), // only on MEXC
    ]);

    $this->coinex->allows('getAvailableMarkets')->andReturn([
        makeMarket('BTC', 'USDT', 200_000),
        makeMarket('ETH', 'USDT', 150_000),
    ]);

    $this->kraken->allows('getAvailableMarkets')->andReturn([
        makeMarket('BTC', 'USD', 800_000),
        makeMarket('ETH', 'USD', 400_000),
    ]);

    $this->artisan('exchanges:discover-pairs', ['--min-volume' => 0])
        ->assertSuccessful()
        ->expectsOutputToContain('BTC/USDT')
        ->expectsOutputToContain('ETH/USDT')
        ->doesntExpectOutputToContain('DOGE/USDT');
});

test('filters out pairs below the min volume threshold', function () {
    $this->mexc->allows('getAvailableMarkets')->andReturn([
        makeMarket('BTC', 'USDT', 500_000),
        makeMarket('XRP', 'USDT', 1_000), // low volume on MEXC
    ]);

    $this->coinex->allows('getAvailableMarkets')->andReturn([
        makeMarket('BTC', 'USDT', 200_000),
        makeMarket('XRP', 'USDT', 2_000),
    ]);

    $this->kraken->allows('getAvailableMarkets')->andReturn([
        makeMarket('BTC', 'USD', 800_000),
        makeMarket('XRP', 'USD', 500), // below threshold
    ]);

    $this->artisan('exchanges:discover-pairs', ['--min-volume' => 50_000])
        ->assertSuccessful()
        ->expectsOutputToContain('BTC/USDT')
        ->doesntExpectOutputToContain('XRP/USDT');
});

test('returns failure when an exchange errors out', function () {
    $this->mexc->allows('getAvailableMarkets')->andThrow(new RuntimeException('API down'));
    $this->coinex->allows('getAvailableMarkets')->andReturn([makeMarket('BTC', 'USDT', 500_000)]);
    $this->kraken->allows('getAvailableMarkets')->andReturn([makeMarket('BTC', 'USD', 800_000)]);

    $this->artisan('exchanges:discover-pairs')
        ->assertFailed();
});

test('respects the top option and shows highest volume pairs first', function () {
    $mexcMarkets = array_map(fn ($i) => makeMarket("COIN{$i}", 'USDT', 100_000 + $i), range(1, 10));
    $coinexMarkets = array_map(fn ($i) => makeMarket("COIN{$i}", 'USDT', 50_000 + $i), range(1, 10));
    $krakenMarkets = array_map(fn ($i) => makeMarket("COIN{$i}", 'USD', 60_000 + $i), range(1, 10));

    $this->mexc->allows('getAvailableMarkets')->andReturn($mexcMarkets);
    $this->coinex->allows('getAvailableMarkets')->andReturn($coinexMarkets);
    $this->kraken->allows('getAvailableMarkets')->andReturn($krakenMarkets);

    $this->artisan('exchanges:discover-pairs', ['--min-volume' => 0, '--top' => 3])
        ->assertSuccessful()
        ->expectsOutputToContain('COIN10/USDT') // highest volume shown first
        ->doesntExpectOutputToContain('COIN1/USDT'); // cut off by --top 3
});
