<?php

/**
 * Scheduler Configuration
 * Defines scheduled jobs and their intervals
 */

return [
    'jobs' => [
        // Product sync
        'product_sync' => [
            'enabled' => env('PRODUCT_SYNC_ENABLED', 'true') === 'true',
            'interval' => env('PRODUCT_SYNC_INTERVAL', '12h'), // 12h, daily, etc.
            'provider' => env('PRODUCT_SYNC_PROVIDER', 'wws'),
            'limit' => null, // null = all, or number
        ],
        
        // Inventory sync
        'inventory_sync' => [
            'enabled' => env('INVENTORY_SYNC_ENABLED', 'true') === 'true',
            'interval' => env('INVENTORY_SYNC_INTERVAL', '5m'), // 5m, 15m, etc.
            'provider' => env('INVENTORY_SYNC_PROVIDER', 'wws'),
            'strategy' => env('INVENTORY_STRATEGY', 'pull'), // pull, push, bidirectional
        ],
        
        // Price sync
        'price_sync' => [
            'enabled' => env('PRICE_SYNC_ENABLED', 'true') === 'true',
            'interval' => env('PRICE_SYNC_INTERVAL', '30m'),
            'provider' => env('PRICE_SYNC_PROVIDER', 'wws'),
            'strategy' => env('PRICE_STRATEGY', 'pull'),
        ],
        
        // Customer sync
        'customer_sync' => [
            'enabled' => env('CUSTOMER_SYNC_ENABLED', 'true') === 'true',
            'interval' => env('CUSTOMER_SYNC_INTERVAL', '1h'),
            'provider' => env('CUSTOMER_SYNC_PROVIDER', 'wws'),
        ],
        
        // Order status sync (pull from providers)
        'order_status_sync' => [
            'enabled' => env('ORDER_STATUS_SYNC_ENABLED', 'true') === 'true',
            'interval' => env('ORDER_STATUS_SYNC_INTERVAL', '5m'),
            'providers' => ['wws'], // Can sync from multiple
        ],
        
        // Order processing (process pending orders from queue)
        'order_processing' => [
            'enabled' => env('ORDER_PROCESSING_ENABLED', 'true') === 'true',
            'interval' => env('ORDER_PROCESSING_INTERVAL', '2m'), // Process every 2 minutes
            'limit' => env('ORDER_PROCESSING_LIMIT', 10), // Process up to 10 orders per run
        ],
    ],
];

