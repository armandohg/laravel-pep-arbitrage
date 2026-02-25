<?php

use App\Exchanges\Mexc;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['exchanges.mexc.base_url' => 'https://api.mexc.com']);
});

it('returns a normalized order book for pep_usdt', function () {
    Http::fake([
        'api.mexc.com/api/v3/depth*' => Http::response([
            'bids' => [
                ['0.000001230', '150000'],
                ['0.000001220', '200000'],
            ],
            'asks' => [
                ['0.000001240', '100000'],
                ['0.000001250', '80000'],
            ],
        ]),
    ]);

    $mexc = new Mexc('test-key', 'test-secret');
    $book = $mexc->getOrderBook('pep_usdt');

    expect($book)->toHaveKeys(['bids', 'asks']);
    expect($book['bids'])->toHaveCount(2);
    expect($book['asks'])->toHaveCount(2);

    expect($book['bids'][0])->toMatchArray(['price' => 0.00000123, 'amount' => 150000.0]);
    expect($book['asks'][0])->toMatchArray(['price' => 0.00000124, 'amount' => 100000.0]);
});

it('returns float price and amount in each order book entry', function () {
    Http::fake([
        'api.mexc.com/api/v3/depth*' => Http::response([
            'bids' => [['0.000001230', '150000']],
            'asks' => [['0.000001240', '100000']],
        ]),
    ]);

    $mexc = new Mexc('test-key', 'test-secret');
    $book = $mexc->getOrderBook('pep_usdt');

    expect($book['bids'][0]['price'])->toBeFloat();
    expect($book['bids'][0]['amount'])->toBeFloat();
    expect($book['asks'][0]['price'])->toBeFloat();
    expect($book['asks'][0]['amount'])->toBeFloat();
});

it('returns normalized balances', function () {
    Http::fake([
        'api.mexc.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'USDT', 'free' => '500.00', 'locked' => '0.00'],
                ['asset' => 'PEP', 'free' => '1000000', 'locked' => '0'],
                ['asset' => 'BTC', 'free' => '0.00', 'locked' => '0.00'],
            ],
        ]),
    ]);

    $mexc = new Mexc('test-key', 'test-secret');
    $balances = $mexc->getBalances();

    expect($balances)->toHaveKeys(['USDT', 'PEP']);
    expect($balances)->not->toHaveKey('BTC');

    expect($balances['USDT'])->toMatchArray(['available' => 500.0]);
    expect($balances['PEP'])->toMatchArray(['available' => 1000000.0]);
    expect($balances['USDT']['available'])->toBeFloat();
});

it('excludes zero-balance currencies', function () {
    Http::fake([
        'api.mexc.com/api/v3/account*' => Http::response([
            'balances' => [
                ['asset' => 'ETH', 'free' => '0.00', 'locked' => '0.00'],
            ],
        ]),
    ]);

    $mexc = new Mexc('test-key', 'test-secret');
    $balances = $mexc->getBalances();

    expect($balances)->toBeEmpty();
});

it('returns the correct fee rate', function () {
    $mexc = new Mexc('test-key', 'test-secret');
    expect($mexc->getTxFee())->toBe(0.0005);
});

it('returns the correct name', function () {
    $mexc = new Mexc('test-key', 'test-secret');
    expect($mexc->getName())->toBe('Mexc');
});
