<?php

namespace App\Models;

use App\Rebalance\Transfer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class RebalanceTransfer extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'from_exchange',
        'to_exchange',
        'currency',
        'amount',
        'network',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'expires_at' => 'datetime',
        ];
    }

    public static function hasPendingTo(string $toExchange, string $currency): bool
    {
        return static::where('to_exchange', $toExchange)
            ->where('currency', $currency)
            ->where('expires_at', '>', now())
            ->exists();
    }

    public static function record(Transfer $transfer, CarbonInterface $expiresAt): self
    {
        return static::create([
            'from_exchange' => $transfer->fromExchange,
            'to_exchange' => $transfer->toExchange,
            'currency' => $transfer->currency,
            'amount' => $transfer->amount,
            'network' => $transfer->network,
            'expires_at' => $expiresAt,
        ]);
    }
}
