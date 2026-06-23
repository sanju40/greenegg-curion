<?php

/**
 * Order Routing Rules
 * Defines how orders are routed to providers
 */

return [
    'strategies' => [
        'sku_based' => [
            'enabled' => true,
            'rules' => [
                // Route SKUs starting with 'WWS-' to WWS provider
                '/^WWS-/' => 'wws',
                '/^SAP-/' => 'sap',
            ],
        ],
        
        'product_mapping' => [
            'enabled' => true,
            // Route based on product_mappings table
        ],
        
        'priority_routing' => [
            'enabled' => false,
            'priority_order' => ['wws', 'sap', 'odoo'],
        ],
        
        'manual_routing' => [
            'enabled' => false,
            // Admin-defined routing per SKU
        ],
        
        'split_orders' => [
            'enabled' => true,
            // Allow splitting orders across providers
        ],
    ],
    
    'default_provider' => env('DEFAULT_ORDER_PROVIDER', 'wws'),
];

