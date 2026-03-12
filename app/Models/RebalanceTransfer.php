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
        'withdrawal_id',
        'withdrawal_status',
        'tx_hash',
        'deposit_confirmed_at',
        'expires_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'deposit_confirmed_at' => 'datetime',
        ];
    }

    public static function hasPendingTo(string $fromExchange, string $toExchange, string $currency): bool
    {
        return static::where('from_exchange', $fromExchange)
            ->where('to_exchange', $toExchange)
            ->where('currency', $currency)
            ->whereNull('settled_at')
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

    /** @return \Illuminate\Database\Eloquent\Collection<int, self> */
    public static function unsettled(): \Illuminate\Database\Eloquent\Collection
    {
        return static::whereNull('settled_at')
            ->get();
    }

    public static function record(Transfer $transfer, CarbonInterface $expiresAt, ?string $withdrawalId = null): self
    {
        return static::create([
            'from_exchange' => $transfer->fromExchange,
            'to_exchange' => $transfer->toExchange,
            'currency' => $transfer->currency,
            'amount' => $transfer->amount,
            'network' => $transfer->network,
            'withdrawal_id' => $withdrawalId,
            'withdrawal_status' => $withdrawalId !== null ? 'pending' : null,
            'expires_at' => $expiresAt,
        ]);
    }
}
