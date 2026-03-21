<?php

namespace App\Console\Commands;

use App\Exchanges\Kraken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ExchangesAutoSellUsdtKrakenCommand extends Command
{
    protected $signature = 'exchanges:auto-sell-usdt-kraken';

    protected $description = 'Sell USDT on Kraken for USD if balance persists above the threshold for more than 10 seconds';

    public function __construct(private readonly Kraken $kraken)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $threshold = (float) config('arbitrage.kraken_usdt_auto_sell_threshold');

        try {
            $usdtAvailable = $this->fetchUsdtBalance();
        } catch (\Throwable $e) {
            Log::error('exchanges:auto-sell-usdt-kraken — failed to fetch Kraken balances', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        if ($usdtAvailable < $threshold) {
            return self::SUCCESS;
        }

        Log::info('exchanges:auto-sell-usdt-kraken — USDT detected, waiting 10 seconds before confirming', [
            'usdt_available' => $usdtAvailable,
            'threshold' => $threshold,
        ]);

        sleep((int) config('arbitrage.kraken_usdt_auto_sell_wait_seconds'));

        try {
            $usdtAvailable = $this->fetchUsdtBalance();
        } catch (\Throwable $e) {
            Log::error('exchanges:auto-sell-usdt-kraken — failed to fetch Kraken balances on second check', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        if ($usdtAvailable < $threshold) {
            Log::info('exchanges:auto-sell-usdt-kraken — USDT gone after wait, no action needed');

            return self::SUCCESS;
        }

        $amount = floor($usdtAvailable * 100) / 100;

        try {
            $this->kraken->sellUsdt($amount);
        } catch (\Throwable $e) {
            Log::error('exchanges:auto-sell-usdt-kraken — sellUsdt failed', ['amount' => $amount, 'error' => $e->getMessage()]);

            return self::FAILURE;
        }

        Log::warning('exchanges:auto-sell-usdt-kraken — sold USDT for USD', ['amount' => $amount]);
        $this->info("Sold {$amount} USDT → USD on Kraken.");

        return self::SUCCESS;
    }

    private function fetchUsdtBalance(): float
    {
        $balances = $this->kraken->getBalances();

        return $balances['USDT']['available'] ?? 0.0;
    }
}
