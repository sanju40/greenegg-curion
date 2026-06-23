<?php

/**
 * CLI: Import Shopify Products into product_mappings
 *
 * Iterates every active product variant in Shopify (cursor-based GraphQL pagination)
 * and creates or updates a row in product_mappings keyed by SKU.
 *
 * Useful to run after:
 *   - Products are added to Shopify manually (not synced through this system)
 *   - Before syncing bundles, to ensure child product mappings exist
 *   - After a store migration or data import
 *
 * Usage:
 *   php cli/import-shopify-mappings.php
 *
 * Output:
 *   Each page of 100 products is reported as it completes.
 *   At the end: totals for created, updated, skipped.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Services\ShopifyMappingImportService;

$config = \App\Core\Config::get();

if (isset($config['cli_enabled']) && !$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

echo "Importing Shopify products into product_mappings...\n";
echo str_repeat('-', 50) . "\n";

$service = new ShopifyMappingImportService();

$lastPage = 0;
$start    = microtime(true);

$stats = $service->importAll(function (int $page, array $stats) use (&$lastPage) {
    $lastPage = $page;
    echo sprintf(
        "Page %d | Products: %d | Variants: %d | Created: %d | Updated: %d | Skipped: %d\n",
        $page,
        $stats['total_products'],
        $stats['total_variants'],
        $stats['created'],
        $stats['updated'],
        $stats['skipped']
    );
});

$elapsed = round(microtime(true) - $start, 1);

echo str_repeat('-', 50) . "\n";
echo "Done in {$elapsed}s\n";
echo "  Pages:          {$stats['pages']}\n";
echo "  Total products: {$stats['total_products']}\n";
echo "  Total variants: {$stats['total_variants']}\n";
echo "  Created:        {$stats['created']}\n";
echo "  Updated:        {$stats['updated']}\n";
echo "  Skipped (no SKU): {$stats['skipped']}\n";
echo "\n";
echo "You can now run the bundle sync:\n";
echo "  php cli/sync-products.php --bundles-only\n";
