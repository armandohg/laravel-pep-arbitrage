<?php

return [
    'mexc' => [
        'api_key' => env('MEXC_API_KEY'),
        'api_secret' => env('MEXC_API_SECRET'),
        'base_url' => 'https://api.mexc.com',
    ],
    'coinex' => [
        'api_key' => env('COINEX_API_KEY'),
        'api_secret' => env('COINEX_API_SECRET'),
        'base_url' => 'https://api.coinex.com',
    ],
    'kraken' => [
        'api_key' => env('KRAKEN_API_KEY'),
        'api_secret' => env('KRAKEN_API_SECRET'),
        'base_url' => env('KRAKEN_BASE_URL', 'https://api.kraken.com/0'),
    ],
];
