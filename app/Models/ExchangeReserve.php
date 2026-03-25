<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeReserve extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'exchange',
        'currency',
        'min_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_amount' => 'float',
        ];
    }

    public static function getFor(string $exchange, string $currency): float
    {
        return (float) static::query()
            ->where('exchange', $exchange)
            ->where('currency', $currency)
            ->value('min_amount') ?? 0.0;
    }

    /**
     * Returns all reserves indexed by exchange and currency.
     *
     * @return array<string, array<string, float>>
     */
    public static function allIndexed(): array
    {
        $indexed = [];

        static::all()->each(function (self $reserve) use (&$indexed) {
            $indexed[$reserve->exchange][$reserve->currency] = $reserve->min_amount;
        });

        return $indexed;
    }
}
