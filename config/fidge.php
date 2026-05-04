<?php

return [
    'leaderboard_epoch_start' => env('LEADERBOARD_EPOCH_START', '2025-01-01'),
    'leaderboard_cycle_days'  => (int) env('LEADERBOARD_CYCLE_DAYS', 14),

    // Marketplace
    'eth_wallet'    => env('ETH_WALLET_ADDRESS', ''),
    'eth_price_usd' => (float) env('ETH_PRICE_USD', 3000),
];
