<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeWallet extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'exchange',
        'asset',
        'network_code',
        'address',
        'memo',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<TransferRoute, $this> */
    public function transferRoutes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TransferRoute::class, 'wallet_id');
    }
}
