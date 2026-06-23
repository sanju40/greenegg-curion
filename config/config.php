<?php

/**
 * Main Configuration File
 * Loads environment variables and provides configuration array
 * Caches the result so file is only processed once
 */

// Return cached config if already loaded
static $cachedConfig = null;
if ($cachedConfig !== null) {
    return $cachedConfig;
}

// Load environment variables
if (!function_exists('env')) {
    function env($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

// Load .env file if it exists (only once)
static $envLoaded = false;
if (!$envLoaded) {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue; // Skip comments and empty lines
            }
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Handle quoted values (single or double quotes)
                $firstChar = substr($value, 0, 1);
                $lastChar = substr($value, -1);
                
                if (($firstChar === '"' && $lastChar === '"') || 
                    ($firstChar === "'" && $lastChar === "'")) {
                    // Remove surrounding quotes
                    $value = substr($value, 1, -1);
                    // Unescape quotes within the value
                    if ($firstChar === '"') {
                        $value = str_replace('\\"', '"', $value);
                        $value = str_replace('\\\\', '\\', $value);
                    } else {
                        $value = str_replace("\\'", "'", $value);
                        $value = str_replace('\\\\', '\\', $value);
                    }
                }
                
                if (!getenv($key)) {
                    putenv("$key=$value");
                    $_ENV[$key] = $value;
                }
            }
        }
    }
    $envLoaded = true;
}

$cachedConfig = [
    'app' => [
        'environment' => env('APP_ENV', 'development'),
        'timezone' => env('APP_TIMEZONE', 'UTC'),
    ],
    
    'web_enabled' => env('WEB_ENABLED', 'true') === 'true',
    'cli_enabled' => env('CLI_ENABLED', 'true') === 'true',
    
    'logging' => [
        'enabled' => env('LOG_ENABLED', 'true') === 'true',
        'min_level' => env('LOG_LEVEL', 'info'), // emergency, alert, critical, error, warning, notice, info, debug
        'file_max_size' => env('LOG_FILE_MAX_SIZE', 10485760), // 10MB default
        'file_retention_days' => env('LOG_RETENTION_DAYS', 30), // Keep logs for 30 days
    ],
    
    'database' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'wws_shopify'),
        'user' => env('DB_USER', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
    ],
    
    'wws' => [
        'base_url' => env('WWS_BASE_URL', 'https://wwsmebo.cloud.onax.ch/datasnap/rest/TWwsServerMethods'),
        'database_id' => env('WWS_DATABASE_ID', '1'),
        'username' => env('WWS_USERNAME', ''),
        'password' => env('WWS_PASSWORD', ''),
        'verify_ssl' => env('WWS_VERIFY_SSL', 'false') === 'true',
        'timeout' => (int)env('WWS_TIMEOUT', 60), // Total request timeout in seconds (default: 60)
        'connect_timeout' => (int)env('WWS_CONNECT_TIMEOUT', 30), // Connection timeout in seconds (default: 30)
        // Optional: when set, order processing (transactions + customer search/create for orders) uses
        // this host instead of base_url. Catalog/product/customer sync keeps using base_url above.
        'orders_base_url' => env('WWS_ORDERS_BASE_URL', ''),
        // Optional: staging DB id for orders; empty = same as database_id
        'orders_database_id' => env('WWS_ORDERS_DATABASE_ID', ''),
        // Optional: credentials for the order host; empty = reuse username/password above
        'orders_username' => env('WWS_ORDERS_USERNAME', ''),
        'orders_password' => env('WWS_ORDERS_PASSWORD', ''),
    ],
    
    'shopify' => [
        'shop_domain' => env('SHOPIFY_SHOP_DOMAIN', ''),
        'api_key' => env('SHOPIFY_API_KEY', ''),
        'api_secret' => env('SHOPIFY_API_SECRET', ''),
        'access_token' => env('SHOPIFY_ACCESS_TOKEN', ''),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-01'),
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', ''),
    ],
    
    'sync' => [
        'batch_size' => (int)env('SYNC_BATCH_SIZE', 50),
        'max_retries' => (int)env('SYNC_MAX_RETRIES', 3),
        'retry_delay' => (int)env('SYNC_RETRY_DELAY', 60),
        'enable_auto_sync' => false,
    ],
    
    'webhook' => [
        'secret' => env('SHOPIFY_WEBHOOK_SECRET', ''),
        'enabled' => true,
    ],

    'uppromote' => [
        'enabled'        => env('UPPROMOTE_ENABLED', 'false') === 'true',
        'api_key'        => env('UPPROMOTE_API_KEY', ''),
        'webhook_secret' => env('UPPROMOTE_WEBHOOK_SECRET', ''),
    ],
    
    'bundles' => [
        // Whether bundle detection and syncing is enabled
        'enabled'              => env('BUNDLES_ENABLED', 'true') === 'true',
        // Comma-separated WWS stockManagement IDs that identify bundle products.
        // 101 = Stkl-Art. var. (variable bundle), 102 = Stkl-Art. fix (fixed bundle).
        // Update this value here or via BUNDLE_STOCK_MANAGEMENT_IDS in .env if WWS changes the IDs.
        'stock_management_ids' => array_map(
            'intval',
            explode(',', env('BUNDLE_STOCK_MANAGEMENT_IDS', '101,102'))
        ),
        // Curion/WWS bundles (stockManagement 101/102): when false, only the parent bundle
        // is synced to Shopify — partsList children are NOT re-synced after bundle create/update.
        // Shopify-native bundles (created in the Bundles app) are unaffected.
        'sync_child_products' => env('BUNDLE_SYNC_CHILD_PRODUCTS', 'false') === 'true',
        // When sync_child_products is false, also skip WWS catalog sync for products that
        // appear only as partsList children of Curion bundles (full product sync path).
        'skip_child_products_in_catalog_sync' => env('BUNDLE_SKIP_CHILD_PRODUCTS_IN_CATALOG', 'true') === 'true',
    ],

    'order_processing' => [
        'max_retries' => (int)env('ORDER_MAX_RETRIES', 3),
        'retry_delay_base_minutes' => (int)env('ORDER_RETRY_DELAY_BASE', 5), // Base delay for exponential backoff
        'order_number_prefix' => env('ORDER_NUMBER_PREFIX', 'BGES-'), // Prefix for order numbers (e.g., BGES-1001)
        'prices_without_vat' => env('PRICES_WITHOUT_VAT', 'true') === 'true', // If true, use prices without VAT from Shopify directly (for product prices)
        'shipping_prices_without_vat' => env('SHIPPING_PRICES_WITHOUT_VAT', 'true') === 'true', // If true, use shipping prices without VAT from Shopify directly
        'default_shipping_method_id' => (int)env('DEFAULT_SHIPPING_METHOD_ID', 1), // Default WWS shipping method ID if no mapping found
        'shipping_method_as_string' => env('SHIPPING_METHOD_AS_STRING', 'false') === 'true', // If true, pass shipping method as string instead of object with ID
        // Collapse Curion bundle children (CURIONBUNDLE parent tag) into parent SKU lines
        // for WWS. Uses Shopify GraphQL lineItemGroup ("Part of:" in admin); legacy
        // partsList fallback when GraphQL has no grouping. Non-Curion bundles unchanged.
        'skip_curion_bundle_child_line_items' => env('ORDER_SKIP_CURION_BUNDLE_CHILD_LINE_ITEMS', 'true') === 'true',
        // When true: build customer + transaction payloads, log them, store in order_queue
        // provider_payload — but do NOT call WWS createTransaction or update Shopify tags.
        // Set ORDER_DRY_RUN=false when ready to send orders to Curion/WWS.
        'dry_run' => env('ORDER_DRY_RUN', 'true') === 'true',
        'shipping_method_mapping' => [
            'Full-Service Delivery' => 7,
            'Full-Service Delivery & Assembly'=>7,
            'Curbside Delivery' => 3,
            'Standard Curbside Delivery'=>3,
            // Map Shopify shipping codes to WWS shipping method IDs or strings
            // Format: 'shopify_code' => wws_id (or string if shipping_method_as_string is true)
            // Example: 'standard' => 1, 'express' => 2, 'priority' => 3
            // Or if using strings: 'standard' => 'Standard Shipping', 'express' => 'Express Shipping'
            // Add mappings as needed based on your Shopify shipping methods
        ],
    ],
];

return $cachedConfig;

