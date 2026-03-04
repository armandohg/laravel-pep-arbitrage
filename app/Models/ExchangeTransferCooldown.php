<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeTransferCooldown extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'exchange',
        'currency',
        'cooldown_minutes',
    ];

    protected function casts(): array
    {
        return [
            'cooldown_minutes' => 'integer',
        ];
    }

    public static function minutesFor(string $exchange, string $currency): int
    {
        return static::where('exchange', $exchange)
            ->where('currency', $currency)
            ->value('cooldown_minutes') ?? 60;
    }
}
