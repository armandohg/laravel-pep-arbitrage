<?php

use App\Livewire\ArbitrageDashboard;
use App\Models\ArbitrageOpportunity;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the dashboard for authenticated users', function () {
    $this->get(route('dashboard'))->assertSuccessful();
});

it('shows all opportunities by default', function () {
    ArbitrageOpportunity::factory()->count(3)->create();

    Livewire::test(ArbitrageDashboard::class)
        ->assertSet('profitLevelFilter', '')
        ->assertSee('All');
});

it('displays stat counts', function () {
    ArbitrageOpportunity::factory()->count(5)->create();

    Livewire::test(ArbitrageDashboard::class)
        ->assertSeeText('5');
});

it('filters by profit level', function () {
    ArbitrageOpportunity::factory()->count(2)->create(['profit_level' => 'Low']);
    ArbitrageOpportunity::factory()->count(3)->create(['profit_level' => 'Extreme']);

    Livewire::test(ArbitrageDashboard::class)
        ->call('setFilter', 'Extreme')
        ->assertSet('profitLevelFilter', 'Extreme');

    $component = Livewire::test(ArbitrageDashboard::class)
        ->call('setFilter', 'Extreme');

    expect($component->get('opportunities')->total())->toBe(3);
});

it('resets the filter and shows all records again', function () {
    ArbitrageOpportunity::factory()->count(2)->create(['profit_level' => 'High']);

    $component = Livewire::test(ArbitrageDashboard::class)
        ->call('setFilter', 'High')
        ->call('setFilter', '');

    expect($component->get('opportunities')->total())->toBe(2);
});

it('clears the filter when set to empty string', function () {
    ArbitrageOpportunity::factory()->count(3)->create(['profit_level' => 'Medium']);

    Livewire::test(ArbitrageDashboard::class)
        ->call('setFilter', 'Medium')
        ->call('setFilter', '')
        ->assertSet('profitLevelFilter', '');

    $component = Livewire::test(ArbitrageDashboard::class)
        ->call('setFilter', '');

    expect($component->get('opportunities')->total())->toBe(3);
});

it('shows the empty state when no records exist', function () {
    Livewire::test(ArbitrageDashboard::class)
        ->assertSee('php artisan arbitrage:find');
});

it('shows the best profit ratio', function () {
    ArbitrageOpportunity::factory()->profitable(0.05)->create();
    ArbitrageOpportunity::factory()->profitable(0.12)->create();

    $component = Livewire::test(ArbitrageDashboard::class);

    expect($component->get('bestProfitRatio'))->toBeCloseTo(0.12);
});
