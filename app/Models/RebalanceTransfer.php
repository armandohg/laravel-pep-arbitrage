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
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public static function hasPendingTo(string $fromExchange, string $toExchange, string $currency): bool
    {
        return static::where('from_exchange', $fromExchange)
            ->where('to_exchange', $toExchange)
            ->where('currency', $currency)
            ->whereNull('settled_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, self> */
    public static function pendingUnsettledForKraken(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('to_exchange', 'Kraken')
            ->where('currency', 'USDT')
            ->whereNull('settled_at')
            ->where('expires_at', '>', now())
            ->get();
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
