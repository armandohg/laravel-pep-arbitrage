<?php

namespace App\Exchanges;

use App\Exchanges\Contracts\ExchangeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class BaseExchange implements ExchangeInterface
{
    protected string $apiBaseUrl;

    public function __construct(
        protected string $apiKey,
        protected string $apiSecret,
    ) {}

    /**
     * Perform an HTTP request with 5-attempt exponential backoff.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function request(string $method, string $url, array $data = [], bool $signed = false): array
    {
        $headers = $this->buildHeaders($method, $url, $data, $signed);
        $lastException = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $response = match (strtoupper($method)) {
                    'GET' => Http::withHeaders($headers)->get($url, $data),
                    'POST' => Http::withHeaders($headers)->post($url, $data),
                    default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
                };

                if ($response->successful()) {
                    return $response->json();
                }

                $lastException = new RuntimeException(
                    "HTTP {$response->status()} from {$url}: {$response->body()}"
                );
            } catch (RuntimeException $e) {
                throw $e;
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
     * Build exchange-specific authentication headers.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    abstract protected function buildHeaders(string $method, string $url, array $data, bool $signed): array;

    /**
     * Returns balances cached for 20 seconds.
     *
     * @return array<string, array{available: float}>
     */
    public function getBalances(): array
    {
        $cacheKey = 'exchange_balances_'.strtolower($this->getName());

        return Cache::remember($cacheKey, 20, function () {
            return $this->normalizeBalances($this->fetchBalances());
        });
    }

    /**
     * Fetch raw balance data from the exchange.
     *
     * @return array<string, mixed>
     */
    abstract protected function fetchBalances(): array;

    /**
     * Normalize raw balance response into standard shape.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, array{available: float}>
     */
    abstract protected function normalizeBalances(array $response): array;
}
