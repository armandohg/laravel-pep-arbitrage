<?php

use App\Models\ArbitrageOpportunity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function executedOpportunity(string $status = 'executed', array $overrides = []): ArbitrageOpportunity
{
    return ArbitrageOpportunity::factory()->create(array_merge([
        'execution_status' => $status,
        'executed_at' => now(),
        'tx_buy_id' => 'buy-123',
        'tx_sell_id' => $status !== 'failed' ? 'sell-456' : null,
        'executed_amount' => 1000000,
        'executed_buy_price' => 0.00000800,
        'executed_sell_price' => $status === 'executed' ? 0.00000850 : null,
    ], $overrides));
}

it('renders without errors', function () {
    Livewire::test('arbitrage-executions')
        ->assertOk();
});

it('shows the empty state when no executions exist', function () {
    Livewire::test('arbitrage-executions')
        ->assertSee('php artisan arbitrage:find --execute');
});

it('does not show unexecuted opportunities', function () {
    ArbitrageOpportunity::factory()->count(3)->create();

    $component = Livewire::test('arbitrage-executions');

    expect($component->get('executions')->total())->toBe(0);
});

it('shows all executions by default', function () {
    executedOpportunity('executed');
    executedOpportunity('partial');
    executedOpportunity('failed');

    $component = Livewire::test('arbitrage-executions')
        ->assertSet('statusFilter', '');

    expect($component->get('executions')->total())->toBe(3);
});

it('filters by executed status', function () {
    executedOpportunity('executed');
    executedOpportunity('executed');
    executedOpportunity('failed');

    $component = Livewire::test('arbitrage-executions')
        ->call('setStatusFilter', 'executed');

    expect($component->get('executions')->total())->toBe(2);
});

it('filters by partial status', function () {
    executedOpportunity('partial');
    executedOpportunity('executed');

    $component = Livewire::test('arbitrage-executions')
        ->call('setStatusFilter', 'partial');

    expect($component->get('executions')->total())->toBe(1);
});

it('filters by failed status', function () {
    executedOpportunity('failed');
    executedOpportunity('failed');
    executedOpportunity('executed');

    $component = Livewire::test('arbitrage-executions')
        ->call('setStatusFilter', 'failed');

    expect($component->get('executions')->total())->toBe(2);
});

it('resets the filter to show all records', function () {
    executedOpportunity('executed');
    executedOpportunity('failed');

    $component = Livewire::test('arbitrage-executions')
        ->call('setStatusFilter', 'failed')
        ->call('setStatusFilter', '');

    expect($component->get('executions')->total())->toBe(2);
});

it('counts stats correctly', function () {
    executedOpportunity('executed');
    executedOpportunity('executed');
    executedOpportunity('partial');
    executedOpportunity('failed');

    $component = Livewire::test('arbitrage-executions');

    expect($component->get('totalExecuted'))->toBe(2);
    expect($component->get('totalPartial'))->toBe(1);
    expect($component->get('totalFailed'))->toBe(1);
});

it('sums profit from executed opportunities only', function () {
    ArbitrageOpportunity::factory()->profitable(0.05)->create([
        'execution_status' => 'executed',
        'executed_at' => now(),
    ]);
    ArbitrageOpportunity::factory()->profitable(0.03)->create([
        'execution_status' => 'failed',
        'executed_at' => now(),
    ]);

    $executedProfit = ArbitrageOpportunity::where('execution_status', 'executed')->value('profit');

    $component = Livewire::test('arbitrage-executions');

    expect($component->get('totalProfit'))->toBeCloseTo($executedProfit, 4);
});
