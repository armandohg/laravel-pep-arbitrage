<?php

namespace App\Models;

use App\Arbitrage\ValueObjects\ExecutionResult;
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
        'execution_status',
        'executed_at',
        'tx_buy_id',
        'tx_sell_id',
        'executed_amount',
        'executed_buy_price',
        'executed_sell_price',
        'execution_error',
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
            'executed_at' => 'datetime',
            'executed_amount' => 'float',
            'executed_buy_price' => 'float',
            'executed_sell_price' => 'float',
        ];
    }

    public function recordExecution(ExecutionResult $result, float $amount, float $buyPrice, float $sellPrice): void
    {
        $this->update([
            'execution_status' => $result->success ? 'executed' : ($result->failedSide === 'sell' ? 'partial' : 'failed'),
            'executed_at' => $result->success || $result->failedSide === 'sell' ? now() : null,
            'tx_buy_id' => $result->buyOrderId,
            'tx_sell_id' => $result->sellOrderId,
            'executed_amount' => $result->success || $result->failedSide === 'sell' ? $amount : null,
            'executed_buy_price' => $result->success || $result->failedSide === 'sell' ? $buyPrice : null,
            'executed_sell_price' => $result->success ? $sellPrice : null,
            'execution_error' => $result->error,
        ]);
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
