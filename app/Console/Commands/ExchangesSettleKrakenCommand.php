<?php

namespace App\Console\Commands;

use App\Exchanges\Kraken;
use App\Models\RebalanceTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ExchangesSettleKrakenCommand extends Command
{
    protected $signature = 'exchanges:settle-kraken';

    protected $description = 'Sell any USDT on Kraken for USD after a rebalance deposit arrives';

    public function __construct(private readonly Kraken $kraken)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $pending = RebalanceTransfer::pendingUnsettledForKraken();

        if ($pending->isEmpty()) {
            return self::SUCCESS;
        }

        try {
            $balances = $this->kraken->getBalances();
        } catch (\Throwable $e) {
            Log::error('exchanges:settle-kraken — failed to fetch Kraken balances', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $usdtAvailable = $balances['USDT']['available'] ?? 0.0;

        if ($usdtAvailable < 1.0) {
            Log::info('exchanges:settle-kraken — USDT deposit not yet arrived on Kraken', ['usdt_available' => $usdtAvailable]);

            return self::SUCCESS;
        }

        $amount = round($usdtAvailable, 2);

        try {
            $this->kraken->sellUsdt($amount);
        } catch (\Throwable $e) {
            Log::error('exchanges:settle-kraken — sellUsdt failed', ['amount' => $amount, 'error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $pending->each(fn (RebalanceTransfer $transfer) => $transfer->update(['settled_at' => now()]));

        $count = $pending->count();
        Log::info('exchanges:settle-kraken — sold USDT for USD', ['amount' => $amount, 'transfers_settled' => $count]);
        $this->info("Sold {$amount} USDT → USD on Kraken. Settled {$count} transfer(s).");

        return self::SUCCESS;
    }
}
