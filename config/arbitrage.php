<?php

return [
    'order_type' => env('ARBITRAGE_ORDER_TYPE', 'limit'),

    /**
     * Safety buffer applied to available balances before calculating the max
     * tradeable amount. Prevents "Insufficient balance" errors caused by
     * balance drift between detection and execution time.
     * 0.005 = 0.5%
     */
    'balance_buffer' => (float) env('ARBITRAGE_BALANCE_BUFFER', 0.005),

    /**
     * Minimum USDT balance on Kraken that triggers an automatic sell to USD.
     * Balances below this threshold are ignored.
     */
    'kraken_usdt_auto_sell_threshold' => (float) env('KRAKEN_AUTO_SELL_USDT_THRESHOLD', 10.0),

    /**
     * Seconds to wait between the first and second USDT balance check.
     * Override to 0 in tests to avoid real sleeps.
     */
    'kraken_usdt_auto_sell_wait_seconds' => (int) env('KRAKEN_AUTO_SELL_USDT_WAIT_SECONDS', 10),
];
