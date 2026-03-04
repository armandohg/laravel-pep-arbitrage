<?php

namespace App\Console\Commands;

use App\Models\RebalanceTransfer;
use App\Rebalance\RebalancePlan;
use App\Rebalance\RebalanceService;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

final class ExchangesRebalanceCommand extends Command
{
    protected $signature = 'exchanges:rebalance
        {--tolerance=0.10 : Allowed deviation per exchange (default 10%)}
        {--network= : Force a specific network (e.g. ERC20, TRC20, PEP)}
        {--execute : Execute the transfers (default is dry-run)}
        {--interactive : Confirm each transfer one by one before executing (requires --execute)}
        {--manual : Output Tinkerwell-ready PHP code for each transfer instead of executing}';

    protected $description = 'Rebalance PEP and USDT across all exchanges';

    public function __construct(private readonly RebalanceService $rebalanceService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tolerance = (float) $this->option('tolerance');
        $execute = (bool) $this->option('execute');
        $interactive = (bool) $this->option('interactive');
        $manual = (bool) $this->option('manual');
        $network = $this->option('network') ?: null;

        if ($interactive && ! $execute) {
            $this->error('--interactive requires --execute.');

            return self::FAILURE;
        }

        $plan = $this->rebalanceService->plan($tolerance, $network);

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
        $this->renderPendingWarnings($plan);

        if ($manual) {
            $this->newLine();
            $this->renderManualCode($plan);
        } elseif ($interactive) {
            $this->executeInteractive($plan);
        } elseif ($execute) {
            $this->warn('Executing transfers...');
            $this->rebalanceService->execute($plan);
            $this->info('Done.');
        } else {
            $this->newLine();
            $this->line('Run with --execute to apply, or --manual to get Tinkerwell code.');
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

    private function renderManualCode(RebalancePlan $plan): void
    {
        $this->line('── Tinkerwell code ──────────────────────────────────────────────────');
        $this->newLine();

        foreach ($plan->transfers as $i => $transfer) {
            $n = $i + 1;
            $amount = number_format($transfer->amount, $transfer->currency === 'PEP' ? 2 : 2, '.', '');

            $lines = [];

            if ($transfer->krakenStep !== null && str_starts_with($transfer->krakenStep, 'buy')) {
                $lines[] = "// ⚠  {$transfer->krakenStep}";
                $lines[] = "app(\\App\\Exchanges\\Kraken::class)->buyUsdt({$amount});";
                $lines[] = '';
            }

            $exchangeClass = "\\App\\Exchanges\\{$transfer->fromExchange}";
            $lines[] = "// Transfer #{$n}: {$transfer->fromExchange} → {$transfer->toExchange} | {$transfer->currency} via {$transfer->network} | fee ~{$transfer->networkFee} {$transfer->currency}";
            $lines[] = "app({$exchangeClass}::class)->withdraw(";
            $lines[] = "    currency: '{$transfer->currency}',";
            $lines[] = "    amount: {$amount},";
            $lines[] = "    address: '{$transfer->address}',";
            $lines[] = "    network: '{$transfer->networkId}',";
            if ($transfer->withdrawKey !== null) {
                $lines[] = "    withdrawKey: '{$transfer->withdrawKey}',";
            }
            $lines[] = ');';

            if ($transfer->krakenStep !== null && str_starts_with($transfer->krakenStep, 'sell')) {
                $lines[] = '';
                $lines[] = "// ⚠  {$transfer->krakenStep}";
            }

            foreach ($lines as $line) {
                $this->line($line);
            }

            $this->newLine();
        }

        $this->line('─────────────────────────────────────────────────────────────────────');
    }

    private function executeInteractive(RebalancePlan $plan): void
    {
        $this->newLine();

        $executed = 0;
        $skipped = 0;

        foreach ($plan->transfers as $i => $transfer) {
            $n = $i + 1;
            $isPending = RebalanceTransfer::hasPendingTo($transfer->toExchange, $transfer->currency);

            $this->line(sprintf(
                '  <fg=cyan>#%d</> %s  %s → %s  via [%s]  fee ~%s %s',
                $n,
                number_format($transfer->amount, $transfer->currency === 'PEP' ? 0 : 8).' '.$transfer->currency,
                $transfer->fromExchange,
                $transfer->toExchange,
                $transfer->network,
                $transfer->networkFee,
                $transfer->currency,
            ));

            if ($transfer->krakenStep !== null) {
                $this->line("         <fg=yellow>⚠  {$transfer->krakenStep}</>");
            }

            if ($isPending) {
                $this->line('         <fg=yellow>⚠  Pending transfer already in progress — skipping.</>');
                $this->newLine();
                $skipped++;

                continue;
            }

            $confirmed = confirm(
                label: "Execute transfer #{$n}?",
                default: false,
            );

            if ($confirmed) {
                $this->rebalanceService->executeTransfer($transfer);
                $this->info("         ✓ Transfer #{$n} sent.");
                $executed++;
            } else {
                $this->line('         Skipped.');
                $skipped++;
            }

            $this->newLine();
        }

        $this->line(sprintf('Done. %d executed, %d skipped.', $executed, $skipped));
    }

    private function renderPendingWarnings(RebalancePlan $plan): void
    {
        $blocked = $this->rebalanceService->pendingBlockedTransfers($plan->transfers);

        if (empty($blocked)) {
            return;
        }

        $this->newLine();
        foreach ($blocked as $transfer) {
            $this->warn("⚠  Pending transfer in progress → {$transfer->toExchange} ({$transfer->currency}). This transfer will be skipped on --execute.");
        }
    }

    private function renderTransfers(RebalancePlan $plan): void
    {
        $this->line('Transfers needed:');

        $rows = [];
        foreach ($plan->transfers as $i => $transfer) {
            $note = $transfer->krakenStep !== null ? '  ⚠  '.$transfer->krakenStep : '';
            $addressPreview = strlen($transfer->address) > 16
                ? substr($transfer->address, 0, 8).'…'.substr($transfer->address, -6)
                : $transfer->address;
            $rows[] = [
                $i + 1,
                $transfer->currency,
                "{$transfer->fromExchange} → {$transfer->toExchange}",
                number_format($transfer->amount, $transfer->currency === 'PEP' ? 0 : 2),
                "[{$transfer->network}]",
                $addressPreview,
                "fee ~{$transfer->networkFee} {$transfer->currency}{$note}",
            ];
        }

        $this->table(['#', 'Currency', 'Route', 'Amount', 'Network', 'Destination', 'Notes'], $rows);
    }
}
