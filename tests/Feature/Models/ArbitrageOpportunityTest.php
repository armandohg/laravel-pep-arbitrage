<?php

use App\Arbitrage\ValueObjects\OpportunityData;
use App\Models\ArbitrageOpportunity;

it('persists a record from OpportunityData with correct column values', function () {
    $data = new OpportunityData(
        buyExchange: 'Mexc',
        sellExchange: 'CoinEx',
        amount: 1000000.0,
        avgBuyPrice: 0.0001,
        avgSellPrice: 0.000102,
        totalBuyCost: 100.0,
        totalSellRevenue: 102.0,
        profit: 2.0,
        profitRatio: 0.02,
        profitLevel: 'Medium',
    );

    $opportunity = ArbitrageOpportunity::fromOpportunityData($data);

    expect($opportunity)->toBeInstanceOf(ArbitrageOpportunity::class)
        ->and($opportunity->exists)->toBeTrue()
        ->and($opportunity->buy_exchange)->toBe('Mexc')
        ->and($opportunity->sell_exchange)->toBe('CoinEx')
        ->and($opportunity->amount)->toBe(1000000.0)
        ->and($opportunity->total_buy_cost)->toBe(100.0)
        ->and($opportunity->total_sell_revenue)->toBe(102.0)
        ->and($opportunity->profit)->toBe(2.0)
        ->and($opportunity->profit_ratio)->toBe(0.02)
        ->and($opportunity->profit_level)->toBe('Medium');

    $this->assertDatabaseHas('arbitrage_opportunities', [
        'buy_exchange' => 'Mexc',
        'sell_exchange' => 'CoinEx',
        'profit_level' => 'Medium',
    ]);
});

it('allows mass assignment of all fillable fields', function () {
    $opportunity = ArbitrageOpportunity::create([
        'buy_exchange' => 'Kraken',
        'sell_exchange' => 'Mexc',
        'amount' => 500000.0,
        'total_buy_cost' => 50.0,
        'total_sell_revenue' => 53.5,
        'profit' => 3.5,
        'profit_ratio' => 0.07,
        'profit_level' => 'VeryHigh',
    ]);

    expect($opportunity->exists)->toBeTrue()
        ->and($opportunity->buy_exchange)->toBe('Kraken')
        ->and($opportunity->profit_level)->toBe('VeryHigh');
});

it('casts numeric columns to floats, not strings', function () {
    $opportunity = ArbitrageOpportunity::factory()->create([
        'amount' => 2000000,
        'total_buy_cost' => 200,
        'total_sell_revenue' => 206,
        'profit' => 6,
        'profit_ratio' => 0.03,
    ]);

    expect($opportunity->amount)->toBeFloat()
        ->and($opportunity->total_buy_cost)->toBeFloat()
        ->and($opportunity->total_sell_revenue)->toBeFloat()
        ->and($opportunity->profit)->toBeFloat()
        ->and($opportunity->profit_ratio)->toBeFloat();
});

it('factory creates a valid record', function () {
    $opportunity = ArbitrageOpportunity::factory()->create();

    expect($opportunity->exists)->toBeTrue()
        ->and($opportunity->buy_exchange)->not->toBe($opportunity->sell_exchange)
        ->and($opportunity->profit_level)->toBeIn(['Low', 'Medium', 'High', 'VeryHigh', 'Extreme']);
});

it('factory extreme state sets profit_level to Extreme', function () {
    $opportunity = ArbitrageOpportunity::factory()->extreme()->create();

    expect($opportunity->profit_level)->toBe('Extreme')
        ->and($opportunity->profit_ratio)->toBeGreaterThanOrEqual(0.10);
});
