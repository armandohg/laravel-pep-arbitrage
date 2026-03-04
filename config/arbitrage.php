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
];
