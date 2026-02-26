<?php

namespace App\Models;

use App\Arbitrage\ValueObjects\OpportunityData;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArbitrageOpportunity extends Model
{
    /** @use HasFactory<\Database\Factories\ArbitrageOpportunityFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'buy_exchange',
        'sell_exchange',
        'amount',
        'total_buy_cost',
        'total_sell_revenue',
        'profit',
        'profit_ratio',
        'profit_level',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'total_buy_cost' => 'float',
            'total_sell_revenue' => 'float',
            'profit' => 'float',
            'profit_ratio' => 'float',
        ];
    }

    public static function fromOpportunityData(OpportunityData $data): static
    {
        return static::create([
            'buy_exchange' => $data->buyExchange,
            'sell_exchange' => $data->sellExchange,
            'amount' => $data->amount,
            'total_buy_cost' => $data->totalBuyCost,
            'total_sell_revenue' => $data->totalSellRevenue,
            'profit' => $data->profit,
            'profit_ratio' => $data->profitRatio,
            'profit_level' => $data->profitLevel,
        ]);
    }
}
