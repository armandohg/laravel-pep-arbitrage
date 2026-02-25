<?php

use App\Exchanges\CoinEx;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['exchanges.coinex.base_url' => 'https://api.coinex.com']);
});

it('returns a normalized order book for pep_usdt', function () {
    Http::fake([
        'api.coinex.com/v2/spot/depth*' => Http::response([
            'data' => [
                'depth' => [
                    'bids' => [
                        ['0.000001230', '150000'],
                        ['0.000001220', '200000'],
                    ],
                    'asks' => [
                        ['0.000001240', '100000'],
                        ['0.000001250', '80000'],
                    ],
                ],
            ],
        ]),
    ]);

    $coinex = new CoinEx('test-key', 'test-secret');
    $book = $coinex->getOrderBook('pep_usdt');

    expect($book)->toHaveKeys(['bids', 'asks']);
    expect($book['bids'])->toHaveCount(2);
    expect($book['asks'])->toHaveCount(2);

    expect($book['bids'][0])->toMatchArray(['price' => 0.00000123, 'amount' => 150000.0]);
    expect($book['asks'][0])->toMatchArray(['price' => 0.00000124, 'amount' => 100000.0]);
});

it('returns float price and amount in each order book entry', function () {
    Http::fake([
        'api.coinex.com/v2/spot/depth*' => Http::response([
            'data' => [
                'depth' => [
                    'bids' => [['0.000001230', '150000']],
                    'asks' => [['0.000001240', '100000']],
                ],
            ],
        ]),
    ]);

    $coinex = new CoinEx('test-key', 'test-secret');
    $book = $coinex->getOrderBook('pep_usdt');

    expect($book['bids'][0]['price'])->toBeFloat();
    expect($book['bids'][0]['amount'])->toBeFloat();
    expect($book['asks'][0]['price'])->toBeFloat();
    expect($book['asks'][0]['amount'])->toBeFloat();
});

it('returns normalized balances', function () {
    Http::fake([
        'api.coinex.com/v2/assets/spot/balance*' => Http::response([
            'data' => [
                ['ccy' => 'USDT', 'available' => '250.00', 'frozen' => '0'],
                ['ccy' => 'PEP', 'available' => '5000000', 'frozen' => '0'],
                ['ccy' => 'ETH', 'available' => '0.00', 'frozen' => '0'],
            ],
        ]),
    ]);

    $coinex = new CoinEx('test-key', 'test-secret');
    $balances = $coinex->getBalances();

    expect($balances)->toHaveKeys(['USDT', 'PEP']);
    expect($balances)->not->toHaveKey('ETH');

    expect($balances['USDT'])->toMatchArray(['available' => 250.0]);
    expect($balances['PEP'])->toMatchArray(['available' => 5000000.0]);
    expect($balances['USDT']['available'])->toBeFloat();
});

it('excludes zero-balance currencies', function () {
    Http::fake([
        'api.coinex.com/v2/assets/spot/balance*' => Http::response([
            'data' => [
                ['ccy' => 'BTC', 'available' => '0.00', 'frozen' => '0'],
            ],
        ]),
    ]);

    $coinex = new CoinEx('test-key', 'test-secret');
    $balances = $coinex->getBalances();

    expect($balances)->toBeEmpty();
});

it('returns the correct fee rate', function () {
    $coinex = new CoinEx('test-key', 'test-secret');
    expect($coinex->getTxFee())->toBe(0.002);
});

it('returns the correct name', function () {
    $coinex = new CoinEx('test-key', 'test-secret');
    expect($coinex->getName())->toBe('CoinEx');
});
