<?php

use App\Models\User;
use App\Rebalance\RebalancePlan;
use App\Rebalance\RebalanceService;
use App\Rebalance\Transfer;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function makeRebalancePlan(array $transfers): RebalancePlan
{
    return new RebalancePlan(
        states: [],
        targets: [],
        transfers: $transfers,
        isBalanced: empty($transfers),
        usdtUsdRate: 1.0,
    );
}

function makeRebalanceXfer(string $currency, string $from = 'Mexc', string $to = 'CoinEx'): Transfer
{
    return new Transfer(
        fromExchange: $from,
        toExchange: $to,
        currency: $currency,
        amount: 100_000.0,
        network: $currency === 'PEP' ? 'PEP' : 'TRC20',
        networkId: $currency === 'PEP' ? 'PEP' : 'TRC20',
        address: 'DEST_ADDRESS',
        networkFee: 1.0,
    );
}

it('ignores unknown currency and does nothing', function () {
    $rebalanceService = $this->mock(RebalanceService::class);
    $rebalanceService->shouldNotReceive('plan');
    $rebalanceService->shouldNotReceive('executeTransfer');

    Livewire::test('exchange-balances')
        ->call('rebalanceCurrency', 'BTC');
});

it('calls plan and executes only PEP transfers', function () {
    $pepTransfer = makeRebalanceXfer('PEP');
    $usdtTransfer = makeRebalanceXfer('USDT');

    $rebalanceService = $this->mock(RebalanceService::class);
    $rebalanceService->shouldReceive('plan')->once()->andReturn(makeRebalancePlan([$pepTransfer, $usdtTransfer]));
    $rebalanceService->shouldReceive('executeTransfer')->once()->with($pepTransfer);

    Livewire::test('exchange-balances')
        ->call('rebalanceCurrency', 'PEP');
});

it('calls plan and executes only USDT transfers', function () {
    $pepTransfer = makeRebalanceXfer('PEP');
    $usdtTransfer = makeRebalanceXfer('USDT');

    $rebalanceService = $this->mock(RebalanceService::class);
    $rebalanceService->shouldReceive('plan')->once()->andReturn(makeRebalancePlan([$pepTransfer, $usdtTransfer]));
    $rebalanceService->shouldReceive('executeTransfer')->once()->with($usdtTransfer);

    Livewire::test('exchange-balances')
        ->call('rebalanceCurrency', 'USDT');
});

it('executes nothing when already balanced for that currency', function () {
    $rebalanceService = $this->mock(RebalanceService::class);
    $rebalanceService->shouldReceive('plan')->once()->andReturn(makeRebalancePlan([]));
    $rebalanceService->shouldNotReceive('executeTransfer');

    Livewire::test('exchange-balances')
        ->call('rebalanceCurrency', 'PEP');
});
