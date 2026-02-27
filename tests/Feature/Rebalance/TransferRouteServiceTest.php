<?php

use App\Models\ExchangeNetwork;
use App\Models\ExchangeWallet;
use App\Models\TransferRoute;
use App\Rebalance\TransferRouteService;

function createRoute(string $from, string $to, string $asset, string $networkCode, string $address, float $fee, string $networkId = ''): void
{
    $wallet = ExchangeWallet::factory()->create([
        'exchange' => $to,
        'asset' => $asset,
        'network_code' => $networkCode,
        'address' => $address,
        'is_active' => true,
    ]);

    ExchangeNetwork::factory()->create([
        'exchange' => $from,
        'asset' => $asset,
        'network_code' => $networkCode,
        'network_id' => $networkId ?: $networkCode,
        'fee' => $fee,
        'withdraw_enabled' => true,
    ]);

    TransferRoute::factory()->create([
        'from_exchange' => $from,
        'to_exchange' => $to,
        'asset' => $asset,
        'network_code' => $networkCode,
        'wallet_id' => $wallet->id,
        'fee' => $fee,
        'is_active' => true,
    ]);
}

test('resolves cheapest route when no network override', function () {
    createRoute('Mexc', 'CoinEx', 'USDT', 'TRC20', 'coinex_usdt_addr', 1.0, 'TRX');
    createRoute('Mexc', 'CoinEx', 'USDT', 'ERC20', 'coinex_usdt_erc_addr', 10.0, 'ETH');

    $service = app(TransferRouteService::class);
    $result = $service->getRouteForTransfer('Mexc', 'CoinEx', 'USDT');

    expect($result['network_code'])->toBe('TRC20')
        ->and($result['fee'])->toBe(1.0)
        ->and($result['address'])->toBe('coinex_usdt_addr')
        ->and($result['network_id'])->toBe('TRX');
});

test('respects network override', function () {
    createRoute('Mexc', 'CoinEx', 'USDT', 'TRC20', 'coinex_usdt_trc', 1.0);
    createRoute('Mexc', 'CoinEx', 'USDT', 'ERC20', 'coinex_usdt_erc', 10.0);

    $service = app(TransferRouteService::class);
    $result = $service->getRouteForTransfer('Mexc', 'CoinEx', 'USDT', 'ERC20');

    expect($result['network_code'])->toBe('ERC20')
        ->and($result['address'])->toBe('coinex_usdt_erc');
});

test('returns memo when present', function () {
    $wallet = ExchangeWallet::factory()->create([
        'exchange' => 'CoinEx',
        'asset' => 'USDT',
        'network_code' => 'TRC20',
        'address' => 'some_address',
        'memo' => 'memo123',
        'is_active' => true,
    ]);

    ExchangeNetwork::factory()->create([
        'exchange' => 'Mexc',
        'asset' => 'USDT',
        'network_code' => 'TRC20',
        'network_id' => 'TRC20',
        'fee' => 1.0,
        'withdraw_enabled' => true,
    ]);

    TransferRoute::factory()->create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'CoinEx',
        'asset' => 'USDT',
        'network_code' => 'TRC20',
        'wallet_id' => $wallet->id,
        'fee' => 1.0,
        'is_active' => true,
    ]);

    $service = app(TransferRouteService::class);
    $result = $service->getRouteForTransfer('Mexc', 'CoinEx', 'USDT');

    expect($result['memo'])->toBe('memo123');
});

test('throws RuntimeException when no active route exists', function () {
    $service = app(TransferRouteService::class);

    expect(fn () => $service->getRouteForTransfer('Mexc', 'CoinEx', 'PEP'))
        ->toThrow(RuntimeException::class, 'No active transfer route');
});

test('throws RuntimeException when forced network has no route', function () {
    createRoute('Mexc', 'CoinEx', 'USDT', 'TRC20', 'coinex_usdt_addr', 1.0);

    $service = app(TransferRouteService::class);

    expect(fn () => $service->getRouteForTransfer('Mexc', 'CoinEx', 'USDT', 'ERC20'))
        ->toThrow(RuntimeException::class, 'ERC20');
});

test('ignores inactive routes', function () {
    $wallet = ExchangeWallet::factory()->create([
        'exchange' => 'CoinEx',
        'asset' => 'PEP',
        'network_code' => 'PEP',
        'address' => 'some_addr',
        'is_active' => true,
    ]);

    ExchangeNetwork::factory()->create([
        'exchange' => 'Mexc',
        'asset' => 'PEP',
        'network_code' => 'PEP',
        'network_id' => 'PEP',
        'fee' => 1.0,
        'withdraw_enabled' => true,
    ]);

    TransferRoute::factory()->create([
        'from_exchange' => 'Mexc',
        'to_exchange' => 'CoinEx',
        'asset' => 'PEP',
        'network_code' => 'PEP',
        'wallet_id' => $wallet->id,
        'fee' => 1.0,
        'is_active' => false,
    ]);

    $service = app(TransferRouteService::class);

    expect(fn () => $service->getRouteForTransfer('Mexc', 'CoinEx', 'PEP'))
        ->toThrow(RuntimeException::class);
});
