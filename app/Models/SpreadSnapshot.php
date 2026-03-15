<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpreadSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'buy_exchange',
        'sell_exchange',
        'spread_ratio',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'spread_ratio' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
