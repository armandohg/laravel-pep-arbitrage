<?php

use App\Models\BalanceSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('shows empty state when there are no snapshots', function () {
    Livewire::test('balance-charts')
        ->assertSee('exchanges:snapshot-balances');
});

test('renders a toggle button and chart for each currency', function () {
    BalanceSnapshot::factory()->create(['currency' => 'PEP', 'total_available' => 1_000_000, 'snapped_at' => now()]);
    BalanceSnapshot::factory()->create(['currency' => 'USDT', 'total_available' => 250.0, 'snapped_at' => now()]);

    // Both currencies appear as toggle buttons and chart headings
    Livewire::test('balance-charts')
        ->assertSeeInOrder(['PEP', 'USDT'])
        ->assertSeeHtml("toggle('PEP')")
        ->assertSeeHtml("toggle('USDT')");
});

test('only includes snapshots from the last 14 days', function () {
    BalanceSnapshot::factory()->create([
        'currency' => 'PEP',
        'total_available' => 500_000,
        'snapped_at' => Carbon::now()->subDays(15),
    ]);
    BalanceSnapshot::factory()->create([
        'currency' => 'USDT',
        'total_available' => 100.0,
        'snapped_at' => Carbon::now()->subDays(7),
    ]);

    $component = Livewire::test('balance-charts');

    $chartData = $component->get('chartData');

    expect($chartData)->toHaveKey('USDT')
        ->and($chartData)->not->toHaveKey('PEP');
});

test('chart data labels and values have the same length', function () {
    BalanceSnapshot::factory()->count(10)->create([
        'currency' => 'PEP',
        'snapped_at' => Carbon::now()->subDays(3),
    ]);

    $chartData = Livewire::test('balance-charts')->get('chartData');

    expect(count($chartData['PEP']['labels']))->toBe(count($chartData['PEP']['data']));
});
