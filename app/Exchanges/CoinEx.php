<?php

namespace App\Exchanges;

class CoinEx extends BaseExchange
{
    /** @var array<string, string> */
    private const SYMBOL_MAP = [
        'pep_usdt' => 'PEPUSDT',
    ];

    public function getName(): string
    {
        return 'CoinEx';
    }

    public function getTxFee(): float
    {
        return 0.002;
    }

    /**
     * @return array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}
     */
    public function getOrderBook(string $symbol): array
    {
        $market = self::SYMBOL_MAP[$symbol] ?? strtoupper(str_replace('_', '', $symbol));
        $url = config('exchanges.coinex.base_url').'/v2/spot/depth';

        $response = $this->request('GET', $url, [
            'market' => $market,
            'limit' => 20,
            'interval' => '0',
        ]);

        $depth = $response['data']['depth'] ?? [];

        return [
            'bids' => $this->normalizeEntries($depth['bids'] ?? []),
            'asks' => $this->normalizeEntries($depth['asks'] ?? []),
        ];
    }

    protected function fetchBalances(): array
    {
        $url = config('exchanges.coinex.base_url').'/v2/assets/spot/balance';

        return $this->request('GET', $url, [], true);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{available: float}>
     */
    protected function normalizeBalances(array $response): array
    {
        $balances = [];

        foreach ($response['data'] ?? [] as $entry) {
            $currency = $entry['ccy'];
            $available = (float) $entry['available'];

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
        $headers = [];

        if ($signed) {
            $timestamp = (string) (int) (microtime(true) * 1000);
            // CoinEx v2: body is JSON for POST, empty string for GET (params go in query string)
            $body = strtoupper($method) === 'POST' && ! empty($data)
                ? json_encode($data, JSON_THROW_ON_ERROR)
                : '';
            $parsedPath = parse_url($url, PHP_URL_PATH) ?? '';
            $toSign = strtoupper($method).$parsedPath.$body.$timestamp;
            $signature = hash_hmac('sha256', $toSign, $this->apiSecret);

            $headers['X-COINEX-KEY'] = $this->apiKey;
            $headers['X-COINEX-SIGN'] = $signature;
            $headers['X-COINEX-TIMESTAMP'] = $timestamp;
        }

        return $headers;
    }

    /**
     * Fetch withdrawal network configurations for the given assets.
     *
     * @param  string[]  $assets
     * @return array<int, array{ccy: string, chains: array<int, array{chain: string, name: string, withdrawFee: string, minWithdrawAmount: string, maxWithdrawAmount: string, isDepositEnable: bool, isWithdrawEnable: bool}>}>
     */
    public function getWithdrawalNetworks(array $assets): array
    {
        $url = config('exchanges.coinex.base_url').'/v2/assets/all-deposit-withdraw-config';
        $response = $this->request('GET', $url, [], true);

        $assetSet = array_flip(array_map('strtoupper', $assets));

        return array_values(array_filter(
            $response['data'] ?? [],
            fn (array $item): bool => isset($assetSet[strtoupper($item['ccy'] ?? '')])
        ));
    }

    /**
     * Fetch the deposit address for a currency + network.
     *
     * @return array{address: string, memo: string|null, network: string}
     */
    public function getDepositAddress(string $currency, string $networkId): array
    {
        $url = config('exchanges.coinex.base_url').'/v2/assets/deposit-address';
        $response = $this->request('GET', $url, ['ccy' => $currency, 'chain' => $networkId], true);

        $data = $response['data'] ?? [];

        return [
            'address' => $data['address'] ?? '',
            'memo' => $data['memo'] ?? null,
            'network' => $data['chain'] ?? $networkId,
        ];
    }

    /**
     * @return array{orderId: string}
     */
    public function placeOrder(string $symbol, string $side, float $amount, string $type, ?float $price = null): array
    {
        $market = self::SYMBOL_MAP[$symbol] ?? strtoupper(str_replace('_', '', $symbol));
        $url = config('exchanges.coinex.base_url').'/v2/spot/order';

        $params = [
            'market' => $market,
            'market_type' => 'spot',
            'side' => strtolower($side),
            'type' => strtolower($type),
            'amount' => (string) $amount,
        ];

        if ($price !== null && strtolower($type) === 'limit') {
            $params['price'] = (string) $price;
        }

        $response = $this->request('POST', $url, $params, true);

        if (($response['code'] ?? 0) !== 0) {
            throw new RuntimeException('CoinEx order error: '.($response['message'] ?? 'unknown error'));
        }

        return ['orderId' => (string) ($response['data']['order_id'] ?? '')];
    }

    /**
     * @return array{withdrawal_id: string|null, raw: array<string, mixed>}
     */
    public function withdraw(string $currency, float $amount, string $address, string $network, ?string $withdrawKey = null): array
    {
        $url = config('exchanges.coinex.base_url').'/v2/assets/withdraw';

        $raw = $this->request('POST', $url, [
            'ccy' => $currency,
            'to_address' => $address,
            'amount' => round($amount, 2),
            'chain' => $network,
        ], true);

        $withdrawId = $raw['data']['withdraw_id'] ?? null;

        return ['withdrawal_id' => $withdrawId !== null ? (string) $withdrawId : null, 'raw' => $raw];
    }

    /**
     * @return array{status: 'pending'|'processing'|'completed'|'failed', tx_hash: string|null}
     */
    public function getWithdrawalStatus(string $withdrawalId, ?string $currency = null): array
    {
        $url = config('exchanges.coinex.base_url').'/v2/assets/withdraw';
        // CoinEx GET signing breaks when query params are included (same issue as getDepositStatus).
        // Fetch recent withdrawals without filters and match in PHP instead.
        $response = $this->request('GET', $url, [], true);

        $entries = $response['data'] ?? [];
        $entry = collect($entries)->first(fn (array $d) => (string) ($d['withdraw_id'] ?? '') === $withdrawalId) ?? [];
        $statusRaw = strtolower((string) ($entry['status'] ?? ''));

        $status = match ($statusRaw) {
            'finished', 'completed' => 'completed',
            'processing', 'confirming' => 'processing',
            'failed', 'rejected', 'cancelled', 'cancel' => 'failed',
            default => 'pending',
        };

        return ['status' => $status, 'tx_hash' => isset($entry['tx_id']) ? (string) $entry['tx_id'] : null];
    }

    /**
     * @return array{status: 'pending'|'confirming'|'completed'|'failed', amount: float|null}
     */
    public function getDepositStatus(string $txHash): array
    {
        $url = config('exchanges.coinex.base_url').'/v2/assets/deposit-history';
        // CoinEx GET signing requires an empty body; passing query params breaks the HMAC.
        // Fetch recent deposits without filters and match in PHP instead.
        $response = $this->request('GET', $url, [], true);

        $entries = $response['data'] ?? [];
        $entry = collect($entries)->first(
            fn (array $d) => isset($d['tx_id']) && strtolower($d['tx_id']) === strtolower($txHash)
        );

        if ($entry === null) {
            return ['status' => 'pending', 'amount' => null];
        }

        $statusRaw = strtolower((string) ($entry['status'] ?? ''));

        $status = match ($statusRaw) {
            'finished', 'completed' => 'completed',
            'confirming', 'processing' => 'confirming',
            'failed', 'rejected', 'cancelled', 'cancel' => 'failed',
            default => 'pending',
        };

        return ['status' => $status, 'amount' => isset($entry['amount']) ? (float) $entry['amount'] : null];
    }

    /**
     * @return array<int, array{symbol: string, base: string, quote_volume_24h: float, price_change_pct: float, last_price: float}>
     */
    public function getAvailableMarkets(): array
    {
        $url = config('exchanges.coinex.base_url').'/v2/spot/ticker';
        $response = $this->request('GET', $url, []);

        $markets = [];

        foreach ($response['data'] ?? [] as $ticker) {
            $market = $ticker['market'] ?? '';

            if (! str_ends_with($market, 'USDT')) {
                continue;
            }

            $base = substr($market, 0, -4);
            $last = (float) ($ticker['last'] ?? 0);
            $open = (float) ($ticker['open'] ?? 0);
            $change = $open > 0 ? (($last - $open) / $open * 100) : 0.0;

            $markets[] = [
                'symbol' => strtolower($base).'_usdt',
                'base' => $base,
                'quote_volume_24h' => (float) ($ticker['value'] ?? 0),
                'price_change_pct' => $change,
                'last_price' => $last,
            ];
        }

        return $markets;
    }

    /**
     * Normalize raw [[price, amount], ...] entries.
     *
     * @param  array<int, array{string, string}>  $entries
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
