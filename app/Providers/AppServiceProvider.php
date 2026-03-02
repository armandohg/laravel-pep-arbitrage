<?php

namespace App\Providers;

use App\Exchanges\CoinEx;
use App\Exchanges\ExchangeRegistry;
use App\Exchanges\Kraken;
use App\Exchanges\Mexc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Mexc::class, fn () => new Mexc(
            config('exchanges.mexc.api_key') ?? '',
            config('exchanges.mexc.api_secret') ?? '',
        ));

        $this->app->singleton(CoinEx::class, fn () => new CoinEx(
            config('exchanges.coinex.api_key') ?? '',
            config('exchanges.coinex.api_secret') ?? '',
        ));

        $this->app->singleton(Kraken::class, fn () => new Kraken(
            config('exchanges.kraken.api_key') ?? '',
            config('exchanges.kraken.api_secret') ?? '',
        ));

        $this->app->bind(ExchangeRegistry::class, fn ($app) => new ExchangeRegistry(
            $app->make(Mexc::class),
            $app->make(CoinEx::class),
            $app->make(Kraken::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
