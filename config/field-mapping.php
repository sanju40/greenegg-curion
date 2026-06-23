<?php

/**
 * Field Mapping Configuration
 * Maps WwsRestService product fields to Shopify product format
 */

return [
    'product' => [
        // Basic product fields
        'title' => [
            'path' => 'name.value.0',
            'default' => 'Untitled Product',
            'transform' => null, // Optional: function name for transformation
        ],
        'body_html' => [
            'path' => 'longDescription.value.0',
            'default' => '',
        ],
        'vendor' => [
            'path' => 'section.description.value.D',
            'default' => 'Big Green Egg',
        ],
        'product_type' => [
            'path' => 'goodsGroup.description.value.D',
            'default' => '',
        ],
        'tags' => [
            'fields' => ['sku', 'synonym'], // Combine multiple fields
            'separator' => ', ',
        ],
        
        // Variant fields
        'variants' => [
            'sku' => [
                'path' => 'sku',
                'required' => true,
            ],
            'price' => [
                'path' => 'salesPrices.0.passantPrice',
                'default' => '0.00',
                'transform' => 'format_price',
            ],
            'compare_at_price' => [
                'path' => 'basePrice',
                'default' => null,
                'transform' => 'format_price',
            ],
            'inventory_quantity' => [
                'path' => 'stock.quantityStock',
                'default' => 0,
            ],
            'inventory_management' => 'shopify', // Always use Shopify inventory
            'barcode' => [
                'path' => 'barcode',
                'default' => null,
            ],
            'weight' => [
                'path' => 'weight',
                'default' => 0,
            ],
            'weight_unit' => 'kg', // Default weight unit
        ],
        
        // Images (if available in API response)
        'images' => [
            'enabled' => false, // Set to true if images are available in API
            'path' => 'images',
        ],
        
        // SEO fields
        'metafields' => [
            'enabled' => false, // Set to true to sync metafields
        ],
    ],
    
    // Transformation functions
    'transformations' => [
        'format_price' => function($value) {
            if (is_numeric($value)) {
                return number_format((float)$value, 2, '.', '');
            }
            return '0.00';
        },
    ],
];

