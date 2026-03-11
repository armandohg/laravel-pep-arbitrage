<?php

namespace App\Console\Commands;

use App\Exchanges\ExchangeRegistry;
use App\Models\RebalanceTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ExchangesTrackTransfersCommand extends Command
{
    protected $signature = 'exchanges:track-transfers';

    protected $description = 'Poll withdrawal status and confirm deposits for unsettled rebalance transfers';

    public function __construct(private readonly ExchangeRegistry $registry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $transfers = RebalanceTransfer::unsettled();

        if ($transfers->isEmpty()) {
            $this->line('No unsettled transfers to track.');

            return self::SUCCESS;
        }

        foreach ($transfers as $transfer) {
            $this->processTransfer($transfer);
        }

        return self::SUCCESS;
    }

    private function processTransfer(RebalanceTransfer $transfer): void
    {
        // If past expiry and still unsettled, mark failed
        if ($transfer->expires_at !== null && $transfer->expires_at->isPast() && $transfer->deposit_confirmed_at === null) {
            $transfer->update(['withdrawal_status' => 'failed', 'settled_at' => now()]);
            Log::warning('exchanges:track-transfers — transfer expired without deposit confirmation', [
                'id' => $transfer->id,
                'from' => $transfer->from_exchange,
                'to' => $transfer->to_exchange,
                'currency' => $transfer->currency,
            ]);
            $this->warn("Transfer #{$transfer->id} expired — marked failed.");

            return;
        }

        // Poll withdrawal status on source exchange
        if ($transfer->withdrawal_id !== null && $transfer->withdrawal_status !== 'completed') {
            try {
                $source = $this->registry->get($transfer->from_exchange);
                $statusResult = $source->getWithdrawalStatus($transfer->withdrawal_id);

                $transfer->update([
                    'withdrawal_status' => $statusResult['status'],
                    'tx_hash' => $statusResult['tx_hash'] ?? $transfer->tx_hash,
                ]);

                $this->line(sprintf(
                    'Transfer #%d (%s → %s %s): withdrawal status = %s',
                    $transfer->id,
                    $transfer->from_exchange,
                    $transfer->to_exchange,
                    $transfer->currency,
                    $statusResult['status'],
                ));

                if ($statusResult['status'] === 'failed') {
                    Log::error('exchanges:track-transfers — withdrawal failed on source exchange', [
                        'id' => $transfer->id,
                        'withdrawal_id' => $transfer->withdrawal_id,
                        'from' => $transfer->from_exchange,
                    ]);

                    return;
                }
            } catch (\Throwable $e) {
                Log::warning('exchanges:track-transfers — could not fetch withdrawal status', [
                    'id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Check if deposit has arrived on destination exchange
        if ($transfer->deposit_confirmed_at === null) {
            try {
                $dest = $this->registry->get($transfer->to_exchange);
                $balances = $dest->getBalances();
                $available = $balances[$transfer->currency]['available'] ?? 0.0;

                if ($available >= $transfer->amount * 0.95) {
                    $transfer->update([
                        'deposit_confirmed_at' => now(),
                        'settled_at' => now(),
                        'withdrawal_status' => 'completed',
                    ]);

                    Log::info('exchanges:track-transfers — deposit confirmed, transfer settled', [
                        'id' => $transfer->id,
                        'to' => $transfer->to_exchange,
                        'currency' => $transfer->currency,
                        'available' => $available,
                    ]);

                    $this->info(sprintf(
                        'Transfer #%d settled — %s %s confirmed on %s.',
                        $transfer->id,
                        number_format($available, 2),
                        $transfer->currency,
                        $transfer->to_exchange,
                    ));
                }
            } catch (\Throwable $e) {
                Log::warning('exchanges:track-transfers — could not fetch dest balances', [
                    'id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
