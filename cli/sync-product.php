<?php

/**
 * CLI: Sync Single Product
 * Usage: php cli/sync-product.php --id=123
 *        php cli/sync-product.php --sku=ABC123
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

if (!$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', ['id:', 'sku:']);

if (!isset($options['id']) && !isset($options['sku'])) {
    echo "Error: Either --id or --sku parameter is required\n";
    echo "Usage: php cli/sync-product.php --id=123\n";
    echo "       php cli/sync-product.php --sku=ABC123\n";
    exit(1);
}

try {
    $syncService = new \App\Core\Services\ProductSyncService();
    
    if (isset($options['id'])) {
        echo "Syncing product ID: {$options['id']}\n";
        $result = $syncService->syncProduct($options['id']);
    } else {
        echo "Syncing product SKU: {$options['sku']}\n";
        // Get product by SKU first, then sync
        $erpProvider = \App\Core\Factory\ProviderFactory::createErpProvider('wws');
        $product = $erpProvider->getProductBySku($options['sku']);
        if (!$product || !isset($product['id'])) {
            \App\Utils\LogHelper::error('Product not found with SKU', [
                'sku' => $options['sku'],
            ]);
            throw new \Exception("Product not found with SKU: {$options['sku']}");
        }
        $result = $syncService->syncProduct($product['id']);
    }
    
    echo "Success!\n";
    if (is_array($result)) {
        echo "Shopify Product ID: " . ($result['id'] ?? 'N/A') . "\n";
    }
    
    exit(0);
} catch (\Exception $e) {
    \App\Utils\LogHelper::critical('Single product sync failed', [
        'product_id' => $options['id'] ?? null,
        'sku' => $options['sku'] ?? null,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

