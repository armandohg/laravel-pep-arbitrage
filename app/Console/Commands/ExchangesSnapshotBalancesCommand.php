<?php

namespace App\Console\Commands;

use App\Exchanges\ExchangeRegistry;
use App\Models\BalanceSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class ExchangesSnapshotBalancesCommand extends Command
{
    protected $signature = 'exchanges:snapshot-balances';

    protected $description = 'Record a balance snapshot (total per currency across all exchanges)';

    public function __construct(private readonly ExchangeRegistry $registry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $totals = [];
        $snappedAt = Carbon::now();

        foreach ($this->registry->all() as $exchange) {
            try {
                foreach ($exchange->getBalances() as $currency => $balance) {
                    $totals[$currency] = ($totals[$currency] ?? 0.0) + $balance['available'];
                }
            } catch (Throwable $e) {
                $this->warn("Could not fetch balances from {$exchange->getName()}: {$e->getMessage()}");
            }
        }

        if (empty($totals)) {
            $this->error('No balances retrieved from any exchange.');

            return self::FAILURE;
        }

        foreach ($totals as $currency => $total) {
            BalanceSnapshot::create([
                'currency' => $currency,
                'total_available' => $total,
                'snapped_at' => $snappedAt,
            ]);
        }

        $this->info(sprintf('Snapped %d currencies at %s.', count($totals), $snappedAt->toDateTimeString()));

        return self::SUCCESS;
    }
}
