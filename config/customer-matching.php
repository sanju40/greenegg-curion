<?php

/**
 * Customer Matching Rules
 * Defines how customers are matched between providers
 */

return [
    'matching_strategy' => [
        'primary' => 'email',      // Primary match field
        'secondary' => 'customer_number', // Fallback
        'tertiary' => 'phone',      // Last resort
    ],
    
    'sync_direction' => [
        'inbound' => true,   // Provider → Shopify
        'outbound' => true,  // Shopify → Provider
    ],
    
    'outbound_triggers' => [
        'customer_created' => true,
        'customer_updated' => true,
        'order_placed' => true, // Create customer if doesn't exist
    ],
];

