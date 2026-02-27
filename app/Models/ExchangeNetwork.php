<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeNetwork extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'exchange',
        'asset',
        'network_code',
        'network_id',
        'network_name',
        'fee',
        'min_amount',
        'max_amount',
        'deposit_enabled',
        'withdraw_enabled',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'float',
            'min_amount' => 'float',
            'max_amount' => 'float',
            'deposit_enabled' => 'boolean',
            'withdraw_enabled' => 'boolean',
        ];
    }
}
