<?php

namespace App\Rebalance;

final class NetworkRouter
{
    /**
     * Returns ['network' => 'TRC20', 'fee' => 1.0]
     *
     * @return array{network: string, fee: float}
     */
    public function bestNetwork(string $currency, string $fromExchange, string $toExchange): array
    {
        if ($currency === 'PEP') {
            $networkKey = 'PEP';
            $network = config("exchanges.networks.{$networkKey}");

            return ['network' => $networkKey, 'fee' => (float) $network['fee']];
        }

        // USDT — try TRC20, fallback ERC20
        foreach (['TRC20', 'ERC20'] as $networkKey) {
            $network = config("exchanges.networks.{$networkKey}");
            $supportedBy = $network['supported_by'] ?? [];

            if (in_array($fromExchange, $supportedBy, true) && in_array($toExchange, $supportedBy, true)) {
                return ['network' => $networkKey, 'fee' => (float) $network['fee']];
            }
        }

        throw new \RuntimeException("No supported network found for {$currency} between {$fromExchange} and {$toExchange}");
    }
}
