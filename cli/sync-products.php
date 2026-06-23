<?php

/**
 * CLI: Sync All Products
 *
 * Fetches products from WWS in pages and syncs them to Shopify.
 * Paging prevents memory exhaustion on large catalogues and keeps
 * each WWS API call small. Shopify rate limiting is handled automatically
 * by the Shopify client (429 retry + bucket throttle).
 *
 * Usage:
 *   php cli/sync-products.php
 *   php cli/sync-products.php --page-size=50
 *   php cli/sync-products.php --page-size=50 --start-offset=200   # resume from offset
 *   php cli/sync-products.php --bundles-only
 *   php cli/sync-products.php --limit=100                          # stop after N products total
 *   php cli/sync-products.php --sync-shopify-data                  # refresh Shopify tags first
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Utils\LogHelper;

$config = \App\Core\Config::get();

if (isset($config['cli_enabled']) && !$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

// ── Parse arguments ──────────────────────────────────────────────────────────
$options         = getopt('', ['page-size:', 'start-offset:', 'limit:', 'bundles-only', 'sync-shopify-data']);
$pageSize        = isset($options['page-size'])    ? max(1, (int)$options['page-size'])    : 50;
$startOffset     = isset($options['start-offset']) ? max(0, (int)$options['start-offset']) : 0;
$totalLimit      = isset($options['limit'])        ? max(1, (int)$options['limit'])        : null;
$bundlesOnly     = isset($options['bundles-only']);
$syncShopifyData = isset($options['sync-shopify-data']);

echo "Starting product synchronisation...\n";
echo "Page size:         {$pageSize}\n";
echo "Start offset:      {$startOffset}\n";
echo "Total limit:       " . ($totalLimit ?? 'unlimited') . "\n";
echo "Bundles only:      " . ($bundlesOnly ? 'yes' : 'no') . "\n";
echo "Sync Shopify data: " . ($syncShopifyData ? 'yes' : 'no') . "\n";
echo str_repeat('-', 50) . "\n";

// ── Pre-sync Shopify data refresh ────────────────────────────────────────────
// When --sync-shopify-data is passed, refresh product_mappings (including the
// shopify_tags column) from Shopify before pushing WWS data out. Keeps
// ProductSyncService's tag union working against the freshest Shopify state.
if ($syncShopifyData) {
    echo "Refreshing Shopify product mappings (incl. tags) before sync...\n";
    try {
        $importService = new \App\Core\Services\ShopifyMappingImportService();
        $importStats   = $importService->importAll();
        echo "  → products: {$importStats['total_products']}"
           . "  variants: {$importStats['total_variants']}"
           . "  created: {$importStats['created']}"
           . "  updated: {$importStats['updated']}"
           . "  tags_updated: {$importStats['tags_updated']}"
           . "  skipped: {$importStats['skipped']}\n";
    } catch (\Exception $e) {
        LogHelper::error('Pre-sync Shopify mapping import failed', [
            'error' => $e->getMessage(),
        ]);
        echo "Shopify mapping import failed: " . $e->getMessage() . "\n";
        echo "Aborting — sync would proceed with stale tag data.\n";
        exit(1);
    }
    echo str_repeat('-', 50) . "\n";
}

// ── Pagination loop ──────────────────────────────────────────────────────────
$syncService  = new \App\Core\Services\ProductSyncService();
$offset       = $startOffset;
$totalSynced  = 0;
$totalErrors  = 0;
$totalSkipped = 0;
$page         = 1;

try {
    while (true) {
        // If a total limit was given, don't fetch more than what's left
        $batchSize = $pageSize;
        if ($totalLimit !== null) {
            $remaining = $totalLimit - ($totalSynced + $totalErrors);
            if ($remaining <= 0) {
                break;
            }
            $batchSize = min($pageSize, $remaining);
        }

        echo "Page {$page} | offset={$offset} | fetching {$batchSize} products from WWS...\n";

        $result = $syncService->syncAllProducts($batchSize, $offset, $bundlesOnly);

        $totalSynced  += $result['synced'];
        $totalErrors  += $result['errors'];
        $totalSkipped += $result['skipped'];

        echo "  → synced: {$result['synced']}  errors: {$result['errors']}  skipped: {$result['skipped']}  fetched: {$result['fetched']}\n";

        // If WWS returned fewer items than requested, we've reached the end
        if ($result['fetched'] < $batchSize) {
            echo "  → Reached end of WWS catalogue.\n";
            break;
        }

        $offset += $result['fetched'];
        $page++;

        // Small pause between pages to be kind to both WWS and Shopify
        sleep(1);
    }
} catch (\Exception $e) {
    LogHelper::critical('Product sync failed', [
        'error'          => $e->getMessage(),
        'offset_at_fail' => $offset,
        'trace'          => $e->getTraceAsString(),
    ]);
    echo "\nFatal error at offset {$offset}: " . $e->getMessage() . "\n";
    echo "To resume, run: php cli/sync-products.php --start-offset={$offset}\n";
    // Print summary before exiting
}

echo str_repeat('-', 50) . "\n";
echo "Synchronisation complete.\n";
echo "Total synced:  {$totalSynced}\n";
echo "Total errors:  {$totalErrors}\n";
echo "Total skipped: {$totalSkipped}\n";

exit($totalErrors > 0 ? 1 : 0);
