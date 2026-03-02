<?php

namespace App\Exchanges;

use App\Exchanges\Contracts\ExchangeInterface;
use RuntimeException;

final class ExchangeRegistry
{
    /** @var array<string, ExchangeInterface> */
    private array $exchanges = [];

    public function __construct(ExchangeInterface ...$exchanges)
    {
        foreach ($exchanges as $exchange) {
            $this->exchanges[$exchange->getName()] = $exchange;
        }
    }

    /**
     * @return ExchangeInterface[]
     */
    public function all(): array
    {
        return array_values($this->exchanges);
    }

    public function get(string $name): ExchangeInterface
    {
        return $this->exchanges[$name] ?? throw new RuntimeException("Unknown exchange: {$name}");
    }
}
