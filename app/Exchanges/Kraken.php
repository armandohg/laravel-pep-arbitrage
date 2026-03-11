<?php

namespace App\Exchanges;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class Kraken extends BaseExchange
{
    /** @var array<string, string> */
    private const SYMBOL_MAP = [
        'pep_usd' => 'PEPUSD',
        'pep_usdt' => 'PEPUSD',
        'usdt_usd' => 'USDTUSD',
    ];

    /**
     * Kraken prefixes (ZUSD, XXBT, etc.) mapped to clean currency codes.
     *
     * @var array<string, string>
     */
    private const ASSET_MAP = [
        'ZUSD' => 'USD',
        'ZEUR' => 'EUR',
        'ZGBP' => 'GBP',
        'XXBT' => 'BTC',
        'XETH' => 'ETH',
        'XXLM' => 'XLM',
        'XXRP' => 'XRP',
        'XLTC' => 'LTC',
    ];

    public function getName(): string
    {
        return 'Kraken';
    }

    public function getTxFee(): float
    {
        return 0.0026;
    }

    /**
     * @return array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}
     */
    public function getOrderBook(string $symbol): array
    {
        $pair = self::SYMBOL_MAP[$symbol] ?? strtoupper(str_replace('_', '', $symbol));
        $url = config('exchanges.kraken.base_url').'/public/Depth';

        $response = $this->request('GET', $url, ['pair' => $pair, 'count' => 25]);

        $result = $response['result'] ?? [];
        $pairData = reset($result);

        if ($pairData === false) {
            throw new RuntimeException("No order book data returned for pair: {$pair}");
        }

        return [
            'bids' => $this->normalizeEntries($pairData['bids'] ?? []),
            'asks' => $this->normalizeEntries($pairData['asks'] ?? []),
        ];
    }

    protected function fetchBalances(): array
    {
        $url = config('exchanges.kraken.base_url').'/private/Balance';

        return $this->requestPrivate($url);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{available: float}>
     */
    protected function normalizeBalances(array $response): array
    {
        $balances = [];

        foreach ($response['result'] ?? [] as $asset => $amount) {
            $currency = self::ASSET_MAP[$asset] ?? $asset;
            $available = (float) $amount;

            if ($available > 0) {
                $balances[$currency] = ['available' => $available];
            }
        }

        return $balances;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function buildHeaders(string $method, string $url, array $data, bool $signed): array
    {
        // Public endpoints need no headers; private auth is handled in requestPrivate()
        return [];
    }

    /**
     * Kraken does not expose a withdrawal network config API — returns empty.
     *
     * @param  string[]  $assets
     * @return array<int, mixed>
     */
    public function getWithdrawalNetworks(array $assets): array
    {
        return [];
    }

    /**
     * Kraken does not expose a deposit address API — returns empty stub.
     *
     * @return array{address: string, memo: string|null, network: string}
     */
    public function getDepositAddress(string $currency, string $networkId): array
    {
        return ['address' => '', 'memo' => null, 'network' => $networkId];
    }

    /**
     * @return array{withdrawal_id: string|null, raw: array<string, mixed>}
     */
    public function withdraw(string $currency, float $amount, string $address, string $network, ?string $withdrawKey = null): array
    {
        $url = config('exchanges.kraken.base_url').'/private/Withdraw';

        $raw = $this->requestPrivate($url, [
            'asset' => $currency,
            'key' => $withdrawKey ?? $address,
            'amount' => (string) $amount,
            'address' => $address,
        ]);

        $refid = $raw['result']['refid'] ?? null;

        return ['withdrawal_id' => $refid !== null ? (string) $refid : null, 'raw' => $raw];
    }

    /**
     * @return array{status: 'pending'|'processing'|'completed'|'failed', tx_hash: string|null}
     */
    public function getWithdrawalStatus(string $withdrawalId): array
    {
        $url = config('exchanges.kraken.base_url').'/private/WithdrawStatus';
        $response = $this->requestPrivate($url, []);

        $entries = $response['result'] ?? [];

        $entry = collect($entries)->firstWhere('refid', $withdrawalId);

        if ($entry === null) {
            return ['status' => 'pending', 'tx_hash' => null];
        }

        $statusRaw = strtolower((string) ($entry['status'] ?? ''));

        $status = match ($statusRaw) {
            'success' => 'completed',
            'settled', 'on hold' => 'processing',
            'failure', 'expired' => 'failed',
            default => 'pending',
        };

        return ['status' => $status, 'tx_hash' => isset($entry['txid']) ? (string) $entry['txid'] : null];
    }

    /**
     * @return array{orderId: string}
     */
    public function placeOrder(string $symbol, string $side, float $amount, string $type, ?float $price = null): array
    {
        $pair = self::SYMBOL_MAP[$symbol] ?? strtoupper(str_replace('_', '', $symbol));
        $url = config('exchanges.kraken.base_url').'/private/AddOrder';

        $params = [
            'pair' => $pair,
            'type' => strtolower($side),
            'ordertype' => strtolower($type),
            'volume' => $amount,
        ];

        if ($price !== null && strtolower($type) === 'limit') {
            $params['price'] = $price;
        }

        $response = $this->requestPrivate($url, $params);

        return ['orderId' => (string) ($response['result']['txid'][0] ?? '')];
    }

    /**
     * @return array<string, mixed>
     */
    public function buyUsdt(float $usdAmount): array
    {
        $url = config('exchanges.kraken.base_url').'/private/AddOrder';

        return $this->requestPrivate($url, [
            'pair' => 'USDTUSD',
            'type' => 'buy',
            'ordertype' => 'market',
            'volume' => $usdAmount,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function sellUsdt(float $usdtAmount): array
    {
        $url = config('exchanges.kraken.base_url').'/private/AddOrder';

        return $this->requestPrivate($url, [
            'pair' => 'USDTUSD',
            'type' => 'sell',
            'ordertype' => 'market',
            'volume' => $usdtAmount,
        ]);
    }

    /**
     * Returns all USD spot markets from Kraken with 24h stats.
     * Fetches all asset pairs then batch-fetches tickers.
     *
     * @return array<int, array{symbol: string, base: string, quote_volume_24h: float, price_change_pct: float, last_price: float}>
     */
    public function getAvailableMarkets(): array
    {
        $pairsUrl = config('exchanges.kraken.base_url').'/public/AssetPairs';
        $pairsResponse = $this->request('GET', $pairsUrl, []);

        /** @var array<string, string> $pairToBase Maps canonical pair name → clean base currency */
        $pairToBase = [];

        foreach ($pairsResponse['result'] ?? [] as $pairName => $pairInfo) {
            // Only USD quote pairs; skip dark-pool (.d) variants
            if (($pairInfo['quote'] ?? '') !== 'ZUSD' || str_ends_with($pairName, '.d')) {
                continue;
            }

            $baseRaw = $pairInfo['base'] ?? '';
            $pairToBase[$pairName] = self::ASSET_MAP[$baseRaw] ?? $baseRaw;
        }

        if (empty($pairToBase)) {
            return [];
        }

        $markets = [];

        foreach (array_chunk(array_keys($pairToBase), 50) as $batch) {
            $tickerUrl = config('exchanges.kraken.base_url').'/public/Ticker';
            $tickerResponse = $this->request('GET', $tickerUrl, ['pair' => implode(',', $batch)]);

            foreach ($tickerResponse['result'] ?? [] as $pairName => $ticker) {
                $base = $pairToBase[$pairName] ?? null;

                if ($base === null) {
                    continue;
                }

                $volume24h = (float) ($ticker['v'][1] ?? 0);  // base volume (24h rolling)
                $vwap24h = (float) ($ticker['p'][1] ?? 0);    // VWAP (24h rolling)
                $last = (float) ($ticker['c'][0] ?? 0);
                $open = (float) ($ticker['o'] ?? 0);

                $markets[] = [
                    'symbol' => strtolower($base).'_usd',
                    'base' => $base,
                    'quote_volume_24h' => $volume24h * $vwap24h,
                    'price_change_pct' => $open > 0 ? (($last - $open) / $open * 100) : 0.0,
                    'last_price' => $last,
                ];
            }
        }

        return $markets;
    }

    /**
     * Perform a signed POST request to a Kraken private endpoint.
     *
     * @param  array<string, mixed>  $postData
     * @return array<string, mixed>
     */
    private function requestPrivate(string $url, array $postData = []): array
    {
        $parsedPath = parse_url($url, PHP_URL_PATH) ?? '';
        $decodedSecret = base64_decode($this->apiSecret);
        $lastException = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $nonce = (string) (int) (microtime(true) * 1000);
                $payload = array_merge($postData, ['nonce' => $nonce]);
                $postBody = http_build_query($payload);

                $message = $parsedPath.hash('sha256', $nonce.$postBody, true);
                $signature = base64_encode(hash_hmac('sha512', $message, $decodedSecret, true));

                $response = Http::asForm()->withHeaders([
                    'API-Key' => $this->apiKey,
                    'API-Sign' => $signature,
                ])->post($url, $payload);

                if ($response->successful()) {
                    $json = $response->json();

                    if (! empty($json['error'])) {
                        throw new RuntimeException('Kraken API error: '.implode(', ', $json['error']));
                    }

                    return $json;
                }

                $lastException = new RuntimeException(
                    "HTTP {$response->status()} from {$url}: {$response->body()}"
                );
            } catch (\Throwable $e) {
                $lastException = $e;
            }

            usleep(500_000 * $attempt);
        }

        throw new RuntimeException(
            "Request to {$url} failed after 5 attempts: ".$lastException?->getMessage(),
            previous: $lastException
        );
    }

    /**
     * Normalize raw [[price, amount, timestamp], ...] entries from Kraken.
     *
     * @param  array<int, array{string, string, int}>  $entries
     * @return array<int, array{price: float, amount: float}>
     */
    private function normalizeEntries(array $entries): array
    {
        return array_map(
            fn (array $entry): array => [
                'price' => (float) $entry[0],
                'amount' => (float) $entry[1],
            ],
            $entries
        );
    }
}
