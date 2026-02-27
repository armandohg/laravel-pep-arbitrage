<?php

namespace App\Console\Commands;

use App\Rebalance\RebalancePlan;
use App\Rebalance\RebalanceService;
use Illuminate\Console\Command;

final class ExchangesRebalanceCommand extends Command
{
    protected $signature = 'exchanges:rebalance
        {--tolerance=0.10 : Allowed deviation per exchange (default 10%)}
        {--execute : Execute the transfers (default is dry-run)}';

    protected $description = 'Rebalance PEP and USDT across all exchanges';

    public function __construct(private readonly RebalanceService $rebalanceService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tolerance = (float) $this->option('tolerance');
        $execute = (bool) $this->option('execute');

        $plan = $this->rebalanceService->plan($tolerance);

        $label = $execute ? '' : ' [DRY-RUN — use --execute to apply]';
        $this->info("Balance rebalance plan{$label}");
        $this->newLine();

        $this->renderCurrentState($plan);
        $this->renderTargetState($plan);

        if ($plan->isBalanced) {
            $this->info('All exchanges are already within the tolerance threshold. No transfers needed.');

            return self::SUCCESS;
        }

        $this->renderTransfers($plan);

        if ($execute) {
            $this->warn('Executing transfers...');
            $this->rebalanceService->execute($plan);
            $this->info('Done.');
        } else {
            $this->newLine();
            $this->line('Run with --execute to apply.');
        }

        return self::SUCCESS;
    }

    private function renderCurrentState(RebalancePlan $plan): void
    {
        $this->line('Current state:');

        $rows = [];
        foreach ($plan->states as $state) {
            $quoteDisplay = number_format($state->quoteUsdt, 2).' USDT';
            if ($state->quoteCurrency === 'USD') {
                $quoteDisplay .= '  ('.number_format($state->quoteNative, 2).' USD)';
            }
            $rows[] = [$state->exchange, number_format($state->pep), $quoteDisplay];
        }

        $totalPep = array_sum(array_map(fn ($s) => $s->pep, $plan->states));
        $totalUsdt = array_sum(array_map(fn ($s) => $s->quoteUsdt, $plan->states));
        $rows[] = ['Total', number_format($totalPep), number_format($totalUsdt, 2).' USDT'];

        $this->table(['Exchange', 'PEP', 'USDT-equiv'], $rows);
    }

    private function renderTargetState(RebalancePlan $plan): void
    {
        if (empty($plan->targets)) {
            return;
        }

        $target = $plan->targets[0];
        $this->line(sprintf(
            'Target per exchange: %s PEP  |  %s USDT',
            number_format($target->pep),
            number_format($target->quoteUsdt, 2),
        ));
        $this->newLine();
    }

    private function renderTransfers(RebalancePlan $plan): void
    {
        $this->line('Transfers needed:');

        $rows = [];
        foreach ($plan->transfers as $i => $transfer) {
            $note = $transfer->krakenStep !== null ? '  ⚠  '.$transfer->krakenStep : '';
            $rows[] = [
                $i + 1,
                $transfer->currency,
                "{$transfer->fromExchange} → {$transfer->toExchange}",
                number_format($transfer->amount, $transfer->currency === 'PEP' ? 0 : 2),
                "[{$transfer->network}]",
                "fee ~{$transfer->networkFee} {$transfer->currency}{$note}",
            ];
        }

        $this->table(['#', 'Currency', 'Route', 'Amount', 'Network', 'Notes'], $rows);
    }
}
