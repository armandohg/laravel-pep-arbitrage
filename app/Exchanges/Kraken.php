<?php

namespace App\Exchanges;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class Kraken extends BaseExchange
{
    /** @var array<string, string> */
    private const SYMBOL_MAP = [
        'pep_usd' => 'PEPUSD',
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
     * @return array<string, mixed>
     */
    public function withdraw(string $currency, float $amount, string $address, string $network): array
    {
        $url = config('exchanges.kraken.base_url').'/private/Withdraw';

        return $this->requestPrivate($url, [
            'asset' => $currency,
            'key' => $address,
            'amount' => $amount,
        ]);
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
     * Perform a signed POST request to a Kraken private endpoint.
     *
     * @param  array<string, mixed>  $postData
     * @return array<string, mixed>
     */
    private function requestPrivate(string $url, array $postData = []): array
    {
        $nonce = (string) (int) (microtime(true) * 1000);
        $postData['nonce'] = $nonce;

        $postBody = http_build_query($postData);
        $parsedPath = parse_url($url, PHP_URL_PATH) ?? '';

        $message = $parsedPath.hash('sha256', $nonce.$postBody, true);
        $decodedSecret = base64_decode($this->apiSecret);
        $signature = base64_encode(hash_hmac('sha512', $message, $decodedSecret, true));

        $lastException = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'API-Key' => $this->apiKey,
                    'API-Sign' => $signature,
                ])->post($url, $postData);

                if ($response->successful()) {
                    return $response->json();
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
