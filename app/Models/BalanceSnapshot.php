<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BalanceSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\BalanceSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'currency',
        'total_available',
        'snapped_at',
    ];

    protected function casts(): array
    {
        return [
            'total_available' => 'float',
            'snapped_at' => 'datetime',
        ];
    }
}
