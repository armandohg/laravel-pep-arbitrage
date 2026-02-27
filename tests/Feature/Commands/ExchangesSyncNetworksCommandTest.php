<?php

use App\Exchanges\CoinEx;
use App\Exchanges\Mexc;
use App\Models\ExchangeNetwork;
use App\Models\ExchangeWallet;
use App\Models\TransferRoute;

function mockSyncExchanges(array $mexcNetworks = [], array $coinexNetworks = []): void
{
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);

    $mexcMock->allows('getWithdrawalNetworks')->andReturn($mexcNetworks);
    $coinexMock->allows('getWithdrawalNetworks')->andReturn($coinexNetworks);

    $mexcMock->allows('getDepositAddress')->andReturn(['address' => 'mexc_addr_'.uniqid(), 'memo' => null, 'network' => 'PEP']);
    $coinexMock->allows('getDepositAddress')->andReturn(['address' => 'coinex_addr_'.uniqid(), 'memo' => null, 'network' => 'PEP']);

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);
}

test('syncs networks and creates exchange_networks records', function () {
    mockSyncExchanges(
        mexcNetworks: [
            [
                'coin' => 'PEP',
                'networkList' => [
                    ['network' => 'PEP', 'name' => 'PepChain', 'withdrawFee' => '1', 'withdrawMin' => '10', 'withdrawMax' => '0', 'depositEnable' => true, 'withdrawEnable' => true],
                ],
            ],
        ],
    );

    $this->artisan('exchanges:sync-networks --asset=PEP')
        ->assertSuccessful();

    expect(ExchangeNetwork::query()->where('exchange', 'Mexc')->where('asset', 'PEP')->exists())->toBeTrue();
});

test('creates exchange_wallets when deposit address is returned', function () {
    mockSyncExchanges(
        mexcNetworks: [
            [
                'coin' => 'PEP',
                'networkList' => [
                    ['network' => 'PEP', 'name' => 'PepChain', 'withdrawFee' => '1', 'withdrawMin' => '10', 'withdrawMax' => '0', 'depositEnable' => true, 'withdrawEnable' => true],
                ],
            ],
        ],
    );

    $this->artisan('exchanges:sync-networks --asset=PEP')
        ->assertSuccessful();

    expect(ExchangeWallet::query()->where('exchange', 'Mexc')->where('asset', 'PEP')->exists())->toBeTrue();
});

test('builds transfer routes after syncing', function () {
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);

    $mexcMock->allows('getWithdrawalNetworks')->andReturn([
        [
            'coin' => 'PEP',
            'networkList' => [
                ['network' => 'PEP', 'name' => 'PepChain', 'withdrawFee' => '1', 'withdrawMin' => '10', 'withdrawMax' => '0', 'depositEnable' => true, 'withdrawEnable' => true],
            ],
        ],
    ]);

    $coinexMock->allows('getWithdrawalNetworks')->andReturn([
        [
            'coin' => 'PEP',
            'networkList' => [
                ['network' => 'PEP', 'name' => 'PepChain', 'withdrawFee' => '1', 'withdrawMin' => '10', 'withdrawMax' => '0', 'depositEnable' => true, 'withdrawEnable' => true],
            ],
        ],
    ]);

    $mexcMock->allows('getDepositAddress')->andReturn(['address' => 'mexc_pep_addr', 'memo' => null, 'network' => 'PEP']);
    $coinexMock->allows('getDepositAddress')->andReturn(['address' => 'coinex_pep_addr', 'memo' => null, 'network' => 'PEP']);

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);

    $this->artisan('exchanges:sync-networks --asset=PEP')
        ->assertSuccessful();

    expect(TransferRoute::query()->where('from_exchange', 'Mexc')->where('to_exchange', 'CoinEx')->where('asset', 'PEP')->exists())->toBeTrue()
        ->and(TransferRoute::query()->where('from_exchange', 'CoinEx')->where('to_exchange', 'Mexc')->where('asset', 'PEP')->exists())->toBeTrue();
});

test('normalizes network codes to canonical form', function () {
    $mexcMock = Mockery::mock(Mexc::class);
    $coinexMock = Mockery::mock(CoinEx::class);

    $mexcMock->allows('getWithdrawalNetworks')->andReturn([
        [
            'coin' => 'USDT',
            'networkList' => [
                ['network' => 'TRX', 'name' => 'TRC20', 'withdrawFee' => '1', 'withdrawMin' => '1', 'withdrawMax' => '0', 'depositEnable' => true, 'withdrawEnable' => true],
            ],
        ],
    ]);

    $coinexMock->allows('getWithdrawalNetworks')->andReturn([]);
    $mexcMock->allows('getDepositAddress')->andReturn(['address' => 'mexc_usdt_addr', 'memo' => null, 'network' => 'TRX']);
    $coinexMock->allows('getDepositAddress')->andReturn(['address' => '', 'memo' => null, 'network' => '']);

    app()->instance(Mexc::class, $mexcMock);
    app()->instance(CoinEx::class, $coinexMock);

    $this->artisan('exchanges:sync-networks --asset=USDT')
        ->assertSuccessful();

    expect(ExchangeNetwork::query()->where('exchange', 'Mexc')->where('network_code', 'TRC20')->exists())->toBeTrue()
        ->and(ExchangeNetwork::query()->where('network_code', 'TRX')->exists())->toBeFalse();
});

test('--exchange option filters which exchange to sync', function () {
    mockSyncExchanges(
        mexcNetworks: [
            [
                'coin' => 'PEP',
                'networkList' => [
                    ['network' => 'PEP', 'name' => 'PepChain', 'withdrawFee' => '1', 'withdrawMin' => '10', 'withdrawMax' => '0', 'depositEnable' => true, 'withdrawEnable' => true],
                ],
            ],
        ],
    );

    $this->artisan('exchanges:sync-networks --exchange=Mexc --asset=PEP')
        ->assertSuccessful();

    expect(ExchangeNetwork::query()->where('exchange', 'Mexc')->exists())->toBeTrue()
        ->and(ExchangeNetwork::query()->where('exchange', 'CoinEx')->exists())->toBeFalse();
});
