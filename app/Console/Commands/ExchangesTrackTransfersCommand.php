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
                $statusResult = $source->getWithdrawalStatus($transfer->withdrawal_id, $transfer->currency);

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
                    $transfer->update(['settled_at' => now()]);

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

        // Check deposit arrival on destination exchange via tx_hash
        if ($transfer->deposit_confirmed_at === null && $transfer->tx_hash !== null) {
            try {
                $dest = $this->registry->get($transfer->to_exchange);
                $depositResult = $dest->getDepositStatus($transfer->tx_hash);

                if ($depositResult['status'] === 'completed') {
                    $transfer->update([
                        'deposit_confirmed_at' => now(),
                        'settled_at' => now(),
                        'withdrawal_status' => 'completed',
                    ]);

                    Log::info('exchanges:track-transfers — deposit confirmed, transfer settled', [
                        'id' => $transfer->id,
                        'to' => $transfer->to_exchange,
                        'currency' => $transfer->currency,
                        'tx_hash' => $transfer->tx_hash,
                    ]);

                    $this->info(sprintf(
                        'Transfer #%d settled — %s deposit confirmed on %s.',
                        $transfer->id,
                        $transfer->currency,
                        $transfer->to_exchange,
                    ));
                } elseif ($depositResult['status'] === 'confirming') {
                    $this->line(sprintf(
                        'Transfer #%d: deposit still confirming on %s.',
                        $transfer->id,
                        $transfer->to_exchange,
                    ));
                } elseif ($depositResult['status'] === 'failed') {
                    $transfer->update(['withdrawal_status' => 'failed', 'settled_at' => now()]);

                    Log::error('exchanges:track-transfers — deposit failed on destination exchange', [
                        'id' => $transfer->id,
                        'to' => $transfer->to_exchange,
                        'tx_hash' => $transfer->tx_hash,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('exchanges:track-transfers — could not fetch deposit status', [
                    'id' => $transfer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
