<?php

namespace App\Rebalance;

use App\Models\ExchangeNetwork;
use App\Models\TransferRoute;
use RuntimeException;

final class TransferRouteService
{
    /**
     * Resolve the best transfer route between two exchanges for a given asset.
     *
     * @return array{network_code: string, network_id: string, fee: float, address: string, memo: string|null}
     */
    public function getRouteForTransfer(
        string $from,
        string $to,
        string $asset,
        ?string $networkCode = null,
    ): array {
        $query = TransferRoute::query()
            ->with('wallet')
            ->where('from_exchange', $from)
            ->where('to_exchange', $to)
            ->where('asset', $asset)
            ->where('is_active', true);

        if ($networkCode !== null) {
            $query->where('network_code', $networkCode);
        } else {
            $query->orderBy('fee', 'asc');
        }

        /** @var TransferRoute|null $route */
        $route = $query->first();

        if ($route === null) {
            throw new RuntimeException(
                "No active transfer route found for {$asset} from {$from} to {$to}"
                .($networkCode !== null ? " via {$networkCode}" : '')
            );
        }

        $networkId = ExchangeNetwork::query()
            ->where('exchange', $from)
            ->where('asset', $asset)
            ->where('network_code', $route->network_code)
            ->value('network_id') ?? $route->network_code;

        return [
            'network_code' => $route->network_code,
            'network_id' => $networkId,
            'fee' => $route->fee,
            'address' => $route->wallet->address,
            'memo' => $route->wallet->memo,
            'withdraw_key' => $route->wallet->withdraw_key,
        ];
    }
}
