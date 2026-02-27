<?php

return [
    'mexc' => [
        'api_key' => env('MEXC_API_KEY'),
        'api_secret' => env('MEXC_API_SECRET'),
        'base_url' => 'https://api.mexc.com',
        'deposit_addresses' => [
            'PEP' => env('MEXC_PEP_DEPOSIT_ADDRESS', ''),
            'USDT' => env('MEXC_USDT_DEPOSIT_ADDRESS', ''),
        ],
    ],
    'coinex' => [
        'api_key' => env('COINEX_API_KEY'),
        'api_secret' => env('COINEX_API_SECRET'),
        'base_url' => 'https://api.coinex.com',
        'deposit_addresses' => [
            'PEP' => env('COINEX_PEP_DEPOSIT_ADDRESS', ''),
            'USDT' => env('COINEX_USDT_DEPOSIT_ADDRESS', ''),
        ],
    ],
    'kraken' => [
        'api_key' => env('KRAKEN_API_KEY'),
        'api_secret' => env('KRAKEN_API_SECRET'),
        'base_url' => env('KRAKEN_BASE_URL', 'https://api.kraken.com/0'),
        'deposit_addresses' => [
            'PEP' => env('KRAKEN_PEP_DEPOSIT_ADDRESS', ''),
            'USDT' => env('KRAKEN_USDT_DEPOSIT_ADDRESS', ''),
        ],
        'withdraw_keys' => [
            'PEP_to_Mexc' => env('KRAKEN_WITHDRAW_KEY_PEP_MEXC', ''),
            'PEP_to_CoinEx' => env('KRAKEN_WITHDRAW_KEY_PEP_COINEX', ''),
            'USDT_to_Mexc' => env('KRAKEN_WITHDRAW_KEY_USDT_MEXC', ''),
            'USDT_to_CoinEx' => env('KRAKEN_WITHDRAW_KEY_USDT_COINEX', ''),
        ],
    ],
    'networks' => [
        'PEP' => ['fee' => 1.0, 'currency' => 'PEP', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
        'TRC20' => ['fee' => 1.0, 'currency' => 'USDT', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
        'ERC20' => ['fee' => 10.0, 'currency' => 'USDT', 'supported_by' => ['Mexc', 'CoinEx', 'Kraken']],
    ],
];
