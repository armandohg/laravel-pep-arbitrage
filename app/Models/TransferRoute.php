<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransferRoute extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'from_exchange',
        'to_exchange',
        'asset',
        'network_code',
        'wallet_id',
        'fee',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<ExchangeWallet, $this> */
    public function wallet(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ExchangeWallet::class, 'wallet_id');
    }
}
