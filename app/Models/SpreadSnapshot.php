<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class SpreadSnapshot extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = [
        'buy_exchange',
        'sell_exchange',
        'spread_ratio',
        'recorded_at',
    ];

    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('recorded_at', '<', now()->subHours(24));
    }

    protected function casts(): array
    {
        return [
            'spread_ratio' => 'float',
            'recorded_at' => 'datetime',
        ];
    }
}
