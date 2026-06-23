<?php

/**
 * Conflict Resolution Rules
 * Defines how to resolve conflicts between providers
 */

return [
    // SKU identity rules
    'sku_authority' => env('SKU_AUTHORITY_PROVIDER', 'wws'), // Provider name
    
    // Price resolution strategy
    'price_strategy' => env('PRICE_STRATEGY', 'provider_authoritative'),
    // Options: provider_authoritative, shopify_authoritative, highest, lowest, priority_provider
    'price_priority_order' => ['wws', 'sap', 'odoo'], // Priority order for price
    
    // Inventory resolution strategy
    'inventory_strategy' => env('INVENTORY_STRATEGY', 'sum'),
    // Options: sum, highest, lowest, authoritative, location_based
    'inventory_priority_order' => ['wws', 'sap'],
    
    // Provider authority rules
    'provider_authority' => [
        'wws' => [
            'products' => true,  // WWS is authoritative for products
            'inventory' => true, // WWS is authoritative for inventory
            'pricing' => true,   // WWS is authoritative for pricing
        ],
        'sap' => [
            'products' => false,
            'inventory' => false,
            'pricing' => false,
        ],
    ],
];

