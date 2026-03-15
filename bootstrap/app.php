<?php

use App\Console\Commands\ExchangesSettleKrakenCommand;
use App\Console\Commands\ExchangesSnapshotBalancesCommand;
use App\Console\Commands\ExchangesTrackTransfersCommand;
use App\Models\ArbitrageSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(ExchangesSnapshotBalancesCommand::class)->everyFifteenMinutes();
        $schedule->command(ExchangesSettleKrakenCommand::class)->everyMinute()->withoutOverlapping();
        $schedule->command(ExchangesTrackTransfersCommand::class)->everyMinute()->withoutOverlapping();
        $schedule->command('exchanges:rebalance --execute')->everyTenMinutes()->withoutOverlapping()->when(
            fn () => ArbitrageSettings::current()->rebalance_enabled
        );
        $schedule->command('model:prune', ['--model' => [\App\Models\SpreadSnapshot::class]])->hourly();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
