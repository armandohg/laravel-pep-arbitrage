<?php

use App\Exchanges\Kraken;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['exchanges.kraken.base_url' => 'https://api.kraken.com/0']);
});

it('returns a normalized order book for pep_usd', function () {
    Http::fake([
        'api.kraken.com/0/public/Depth*' => Http::response([
            'result' => [
                'PEPUSD' => [
                    'bids' => [
                        ['0.000001230', '150000', 1700000000],
                        ['0.000001220', '200000', 1700000001],
                    ],
                    'asks' => [
                        ['0.000001240', '100000', 1700000002],
                        ['0.000001250', '80000', 1700000003],
                    ],
                ],
            ],
        ]),
    ]);

    $kraken = new Kraken('test-key', base64_encode('test-secret'));
    $book = $kraken->getOrderBook('pep_usd');

    expect($book)->toHaveKeys(['bids', 'asks']);
    expect($book['bids'])->toHaveCount(2);
    expect($book['asks'])->toHaveCount(2);

    expect($book['bids'][0])->toMatchArray(['price' => 0.00000123, 'amount' => 150000.0]);
    expect($book['asks'][0])->toMatchArray(['price' => 0.00000124, 'amount' => 100000.0]);
});

it('returns a normalized order book for usdt_usd', function () {
    Http::fake([
        'api.kraken.com/0/public/Depth*' => Http::response([
            'result' => [
                'USDTUSD' => [
                    'bids' => [['0.9998', '10000', 1700000000]],
                    'asks' => [['1.0002', '8000', 1700000001]],
                ],
            ],
        ]),
    ]);

    $kraken = new Kraken('test-key', base64_encode('test-secret'));
    $book = $kraken->getOrderBook('usdt_usd');

    expect($book['bids'][0])->toMatchArray(['price' => 0.9998, 'amount' => 10000.0]);
    expect($book['asks'][0])->toMatchArray(['price' => 1.0002, 'amount' => 8000.0]);
});

it('strips the timestamp from kraken order book entries', function () {
    Http::fake([
        'api.kraken.com/0/public/Depth*' => Http::response([
            'result' => [
                'PEPUSD' => [
                    'bids' => [['0.000001230', '150000', 1700000000]],
                    'asks' => [['0.000001240', '100000', 1700000002]],
                ],
            ],
        ]),
    ]);

    $kraken = new Kraken('test-key', base64_encode('test-secret'));
    $book = $kraken->getOrderBook('pep_usd');

    expect($book['bids'][0])->toHaveKeys(['price', 'amount']);
    expect($book['bids'][0])->not->toHaveKey('timestamp');
    expect($book['bids'][0]['price'])->toBeFloat();
    expect($book['bids'][0]['amount'])->toBeFloat();
});

it('normalizes kraken asset codes to clean currency names', function () {
    Http::fake([
        'api.kraken.com/0/private/Balance' => Http::response([
            'result' => [
                'ZUSD' => '1000.00',
                'XXBT' => '0.05',
                'XETH' => '2.00',
                'PEPUSD' => '0.00',
            ],
        ]),
    ]);

    $kraken = new Kraken('test-key', base64_encode('test-secret'));
    $balances = $kraken->getBalances();

    expect($balances)->toHaveKeys(['USD', 'BTC', 'ETH']);
    expect($balances)->not->toHaveKey('ZUSD');
    expect($balances)->not->toHaveKey('XXBT');
    expect($balances)->not->toHaveKey('XETH');

    expect($balances['USD'])->toMatchArray(['available' => 1000.0]);
    expect($balances['BTC'])->toMatchArray(['available' => 0.05]);
});

it('excludes zero-balance assets', function () {
    Http::fake([
        'api.kraken.com/0/private/Balance' => Http::response([
            'result' => [
                'ZUSD' => '100.00',
                'XXBT' => '0.00',
            ],
        ]),
    ]);

    $kraken = new Kraken('test-key', base64_encode('test-secret'));
    $balances = $kraken->getBalances();

    expect($balances)->toHaveKey('USD');
    expect($balances)->not->toHaveKey('BTC');
});

it('returns the correct fee rate', function () {
    $kraken = new Kraken('test-key', base64_encode('test-secret'));
    expect($kraken->getTxFee())->toBe(0.0026);
});

it('returns the correct name', function () {
    $kraken = new Kraken('test-key', base64_encode('test-secret'));
    expect($kraken->getName())->toBe('Kraken');
});
