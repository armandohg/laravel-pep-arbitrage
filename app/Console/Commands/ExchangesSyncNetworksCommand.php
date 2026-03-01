<?php

namespace App\Console\Commands;

use App\Exchanges\CoinEx;
use App\Exchanges\Mexc;
use App\Models\ExchangeNetwork;
use App\Models\ExchangeWallet;
use App\Models\TransferRoute;
use Illuminate\Console\Command;

final class ExchangesSyncNetworksCommand extends Command
{
    protected $signature = 'exchanges:sync-networks
        {--exchange= : Sync only a specific exchange (Mexc or CoinEx)}
        {--asset=* : Sync only specific assets (default: PEP USDT)}';

    protected $description = 'Sync withdrawal networks and deposit addresses from exchanges, then build transfer routes';

    /** @var array<string, string> */
    private const NETWORK_CANONICAL = [
        'PEP' => 'PEP',
        'PEPCHAIN' => 'PEP',
        'TRX' => 'TRC20',
        'TRC20' => 'TRC20',
        'trc20' => 'TRC20',
        'ETH' => 'ERC20',
        'ERC20' => 'ERC20',
        'erc20' => 'ERC20',
        'BEP20' => 'BSC',
        'BSC' => 'BSC',
        'bsc' => 'BSC',
    ];

    public function __construct(
        private readonly Mexc $mexc,
        private readonly CoinEx $coinEx,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $assets = $this->option('asset') ?: ['PEP', 'USDT'];
        $exchangeFilter = $this->option('exchange');

        $exchanges = [
            'Mexc' => $this->mexc,
            'CoinEx' => $this->coinEx,
        ];

        if ($exchangeFilter !== null) {
            $exchanges = array_filter($exchanges, fn ($key) => $key === $exchangeFilter, ARRAY_FILTER_USE_KEY);
        }

        foreach ($exchanges as $name => $exchange) {
            $this->info("Syncing {$name}...");
            $this->syncExchange($name, $exchange, $assets);
        }

        $this->info('Seeding Kraken routes from config...');
        $this->seedKrakenRoutes($assets);

        $this->info('Building transfer routes...');
        $this->buildTransferRoutes($assets);

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @param  \App\Exchanges\Mexc|\App\Exchanges\CoinEx  $exchange
     * @param  string[]  $assets
     */
    private function syncExchange(string $name, object $exchange, array $assets): void
    {
        $networksData = $exchange->getWithdrawalNetworks($assets);

        foreach ($networksData as $coinData) {
            $asset = strtoupper($coinData['coin'] ?? $coinData['ccy'] ?? '');
            $networkList = $coinData['networkList'] ?? $coinData['chains'] ?? [];

            foreach ($networkList as $net) {
                $networkId = $net['network'] ?? $net['chain'] ?? '';
                $networkCode = $this->canonicalize($networkId);
                $depositEnabled = (bool) ($net['depositEnable'] ?? $net['isDepositEnable'] ?? true);
                $withdrawEnabled = (bool) ($net['withdrawEnable'] ?? $net['isWithdrawEnable'] ?? true);

                ExchangeNetwork::query()->updateOrCreate(
                    ['exchange' => $name, 'asset' => $asset, 'network_code' => $networkCode],
                    [
                        'network_id' => $networkId,
                        'network_name' => $net['name'] ?? $networkCode,
                        'fee' => (float) ($net['withdrawFee'] ?? 0),
                        'min_amount' => (float) ($net['withdrawMin'] ?? $net['minWithdrawAmount'] ?? 0),
                        'max_amount' => (float) ($net['withdrawMax'] ?? $net['maxWithdrawAmount'] ?? 0),
                        'deposit_enabled' => $depositEnabled,
                        'withdraw_enabled' => $withdrawEnabled,
                    ]
                );

                // Only fetch deposit addresses for canonical networks we recognise.
                // Exotic network IDs (e.g. "BNB SMART CHAIN(BEP20)") contain special
                // characters that break HMAC signature computation.
                $isCanonical = array_key_exists($networkId, self::NETWORK_CANONICAL);

                if ($depositEnabled && $isCanonical) {
                    try {
                        $addrData = $exchange->getDepositAddress($asset, $networkId);
                        if (! empty($addrData['address'])) {
                            ExchangeWallet::query()->updateOrCreate(
                                ['exchange' => $name, 'asset' => $asset, 'network_code' => $networkCode],
                                [
                                    'address' => $addrData['address'],
                                    'memo' => $addrData['memo'] ?? null,
                                    'is_active' => true,
                                ]
                            );
                        }
                    } catch (\Throwable $e) {
                        $this->warn("  Could not fetch deposit address for {$asset}/{$networkCode} on {$name}: {$e->getMessage()}");
                    }
                }
            }
        }
    }

    /**
     * Seed Kraken routes from config (Kraken has no sync API).
     *
     * @param  string[]  $assets
     */
    private function seedKrakenRoutes(array $assets): void
    {
        $krakenNetworks = [
            'PEP' => 'PEP',
            'USDT' => 'TRC20',
        ];

        foreach ($assets as $asset) {
            $networkCode = $krakenNetworks[$asset] ?? null;
            if ($networkCode === null) {
                continue;
            }

            $depositAddress = config("exchanges.kraken.deposit_addresses.{$asset}", '');
            if (! empty($depositAddress)) {
                ExchangeWallet::query()->updateOrCreate(
                    ['exchange' => 'Kraken', 'asset' => $asset, 'network_code' => $networkCode],
                    ['address' => $depositAddress, 'memo' => null, 'is_active' => true]
                );
            }

            // Kraken withdrawal uses named keys, store as address field
            foreach (['Mexc', 'CoinEx'] as $toExchange) {
                $keyName = "{$asset}_to_{$toExchange}";
                $withdrawKey = config("exchanges.kraken.withdraw_keys.{$keyName}", '');

                if (! empty($withdrawKey)) {
                    $fee = (float) config("exchanges.networks.{$networkCode}.fee", 0);

                    ExchangeNetwork::query()->updateOrCreate(
                        ['exchange' => 'Kraken', 'asset' => $asset, 'network_code' => $networkCode],
                        [
                            'network_id' => $networkCode,
                            'network_name' => $networkCode,
                            'fee' => $fee,
                            'min_amount' => 0,
                            'max_amount' => 0,
                            'deposit_enabled' => true,
                            'withdraw_enabled' => true,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Build transfer_routes from all permutations of exchanges where:
     * - from has withdraw_enabled=true for that asset/network
     * - to has an active wallet for that asset/network
     *
     * @param  string[]  $assets
     */
    private function buildTransferRoutes(array $assets): void
    {
        $exchangeNames = ['Mexc', 'CoinEx', 'Kraken'];

        foreach ($exchangeNames as $from) {
            foreach ($exchangeNames as $to) {
                if ($from === $to) {
                    continue;
                }

                foreach ($assets as $asset) {
                    $fromNetworks = ExchangeNetwork::query()
                        ->where('exchange', $from)
                        ->where('asset', $asset)
                        ->where('withdraw_enabled', true)
                        ->get();

                    foreach ($fromNetworks as $network) {
                        $toWallet = ExchangeWallet::query()
                            ->where('exchange', $to)
                            ->where('asset', $asset)
                            ->where('network_code', $network->network_code)
                            ->where('is_active', true)
                            ->first();

                        if ($toWallet === null) {
                            continue;
                        }

                        TransferRoute::query()->updateOrCreate(
                            [
                                'from_exchange' => $from,
                                'to_exchange' => $to,
                                'asset' => $asset,
                                'network_code' => $network->network_code,
                            ],
                            [
                                'wallet_id' => $toWallet->id,
                                'fee' => $network->fee,
                                'is_active' => true,
                            ]
                        );
                    }
                }
            }
        }
    }

    private function canonicalize(string $networkId): string
    {
        return self::NETWORK_CANONICAL[$networkId] ?? strtoupper($networkId);
    }
}
