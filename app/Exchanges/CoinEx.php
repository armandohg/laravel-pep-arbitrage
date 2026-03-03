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
            $body = empty($data) ? '' : json_encode($data, JSON_THROW_ON_ERROR);
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

        return ['orderId' => (string) ($response['data']['order_id'] ?? '')];
    }

    /**
     * @return array<string, mixed>
     */
    public function withdraw(string $currency, float $amount, string $address, string $network): array
    {
        $url = config('exchanges.coinex.base_url').'/v2/assets/withdraw';

        return $this->request('POST', $url, [
            'ccy' => $currency,
            'to_address' => $address,
            'amount' => $amount,
            'chain' => $network,
        ], true);
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
