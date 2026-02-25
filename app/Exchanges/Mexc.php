<?php

namespace App\Exchanges;

class Mexc extends BaseExchange
{
    /** @var array<string, string> */
    private const SYMBOL_MAP = [
        'pep_usdt' => 'PEPUSDT',
    ];

    public function getName(): string
    {
        return 'Mexc';
    }

    public function getTxFee(): float
    {
        return 0.0005;
    }

    /**
     * @return array{bids: array<int, array{price: float, amount: float}>, asks: array<int, array{price: float, amount: float}>}
     */
    public function getOrderBook(string $symbol): array
    {
        $mexcSymbol = self::SYMBOL_MAP[$symbol] ?? strtoupper(str_replace('_', '', $symbol));
        $url = config('exchanges.mexc.base_url').'/api/v3/depth';

        $response = $this->request('GET', $url, ['symbol' => $mexcSymbol, 'limit' => 20]);

        return [
            'bids' => $this->normalizeEntries($response['bids'] ?? []),
            'asks' => $this->normalizeEntries($response['asks'] ?? []),
        ];
    }

    protected function fetchBalances(): array
    {
        $url = config('exchanges.mexc.base_url').'/api/v3/account';

        return $this->request('GET', $url, [], true);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{available: float}>
     */
    protected function normalizeBalances(array $response): array
    {
        $balances = [];

        foreach ($response['balances'] ?? [] as $entry) {
            $currency = $entry['asset'];
            $available = (float) $entry['free'];

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
        $headers = ['X-MEXC-APIKEY' => $this->apiKey];

        if ($signed) {
            $timestamp = (int) (microtime(true) * 1000);
            $data['timestamp'] = $timestamp;
            $data['recvWindow'] = 5000;

            $queryString = http_build_query($data);
            $signature = hash_hmac('sha256', $queryString, $this->apiSecret);

            $data['signature'] = $signature;

            // Append signed params to URL for GET requests — handled in getOrderBook via request()
            // For signed requests we pass data with signature already merged
        }

        return $headers;
    }

    /**
     * Override request for signed GET calls to append signature to query string.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function request(string $method, string $url, array $data = [], bool $signed = false): array
    {
        if ($signed && strtoupper($method) === 'GET') {
            $timestamp = (int) (microtime(true) * 1000);
            $data['timestamp'] = $timestamp;
            $data['recvWindow'] = 5000;

            $queryString = http_build_query($data);
            $data['signature'] = hash_hmac('sha256', $queryString, $this->apiSecret);
        }

        return parent::request($method, $url, $data, $signed);
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
