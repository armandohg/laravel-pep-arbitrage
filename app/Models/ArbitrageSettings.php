<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArbitrageSettings extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'discovery_interval',
        'min_profit_ratio',
        'sustain_duration',
        'sustain_interval',
        'stability',
        'min_amount',
        'execute_orders',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discovery_interval' => 'integer',
            'min_profit_ratio' => 'float',
            'sustain_duration' => 'integer',
            'sustain_interval' => 'integer',
            'stability' => 'float',
            'min_amount' => 'float',
            'execute_orders' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'discovery_interval' => 5,
            'min_profit_ratio' => 0.003,
            'sustain_duration' => 10,
            'sustain_interval' => 2,
            'stability' => 0.5,
            'min_amount' => 0,
            'execute_orders' => false,
        ]);
    }
}
