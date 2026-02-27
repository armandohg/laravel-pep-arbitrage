<?php

use App\Rebalance\NetworkRouter;

beforeEach(function () {
    config()->set('exchanges.networks', [
        'PEP' => ['fee' => 1.0, 'currency' => 'PEP', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
        'TRC20' => ['fee' => 1.0, 'currency' => 'USDT', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
        'ERC20' => ['fee' => 10.0, 'currency' => 'USDT', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
    ]);
});

test('picks PEP network for PEP currency', function () {
    $router = new NetworkRouter;
    $result = $router->bestNetwork('PEP', 'Mexc', 'CoinEx');

    expect($result['network'])->toBe('PEP')
        ->and($result['fee'])->toBe(1.0);
});

test('picks TRC20 for USDT when both exchanges support it', function () {
    $router = new NetworkRouter;
    $result = $router->bestNetwork('USDT', 'Mexc', 'CoinEx');

    expect($result['network'])->toBe('TRC20')
        ->and($result['fee'])->toBe(1.0);
});

test('falls back to ERC20 when TRC20 is not supported by one exchange', function () {
    config()->set('exchanges.networks.TRC20.supported_by', ['Mexc', 'CoinEx']);

    $router = new NetworkRouter;
    $result = $router->bestNetwork('USDT', 'Mexc', 'Kraken');

    expect($result['network'])->toBe('ERC20')
        ->and($result['fee'])->toBe(10.0);
});
