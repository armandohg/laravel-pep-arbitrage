<?php

namespace Database\Seeders;

use App\Models\ExchangeTransferCooldown;
use Illuminate\Database\Seeder;

class ExchangeTransferCooldownSeeder extends Seeder
{
    public function run(): void
    {
        $cooldowns = [
            ['exchange' => 'Mexc',   'currency' => 'PEP',  'cooldown_minutes' => 12],
            ['exchange' => 'Mexc',   'currency' => 'USDT', 'cooldown_minutes' => 12],
            ['exchange' => 'CoinEx', 'currency' => 'PEP',  'cooldown_minutes' => 20],
            ['exchange' => 'CoinEx', 'currency' => 'USDT', 'cooldown_minutes' => 20],
            ['exchange' => 'Kraken', 'currency' => 'PEP',  'cooldown_minutes' => 60],
            ['exchange' => 'Kraken', 'currency' => 'USDT', 'cooldown_minutes' => 60],
        ];

        foreach ($cooldowns as $cooldown) {
            ExchangeTransferCooldown::updateOrCreate(
                ['exchange' => $cooldown['exchange'], 'currency' => $cooldown['currency']],
                ['cooldown_minutes' => $cooldown['cooldown_minutes']],
            );
        }
    }
}
