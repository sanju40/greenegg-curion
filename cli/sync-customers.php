<?php

/**
 * CLI: Sync Customers from ERP to Shopify
 * Usage: php cli/sync-customers.php [--provider=wws] [--limit=100] [--offset=0] [--skip-existing] [--resume]
 * 
 * Options:
 *   --provider=wws        Provider name (default: wws)
 *   --limit=100           Batch size (default: 100, or from last progress)
 *   --offset=0            Starting offset (default: resume from last position)
 *   --skip-existing       Skip customers that already exist in Shopify (default: true)
 *   --no-skip-existing    Update existing customers (not recommended)
 *   --resume              Resume from last synced position (default behavior)
 *   --reset               Reset sync progress and start from beginning
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Config;
use App\Core\Services\CustomerSyncService;
use App\Core\Factory\ProviderFactory;
use App\Database\Database;

$config = Config::get();

if (isset($config['cli_enabled']) && !$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', ['provider:', 'limit:', 'offset:', 'skip-existing', 'no-skip-existing', 'resume', 'reset']);
$providerName = $options['provider'] ?? 'wws';
$limit = isset($options['limit']) ? (int)$options['limit'] : null;
$offset = isset($options['offset']) ? (int)$options['offset'] : null;
$skipExisting = !isset($options['no-skip-existing']); // Default: skip existing
$reset = isset($options['reset']);

// Reset progress if requested
if ($reset) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("DELETE FROM customer_sync_progress WHERE provider_name = ?");
    $stmt->execute([$providerName]);
    echo "Sync progress reset for provider: {$providerName}\n";
    $offset = 0;
}

echo "Starting customer synchronization...\n";
echo "Provider: {$providerName}\n";
echo "Batch size: " . ($limit ?? 'auto (from progress)') . "\n";
echo "Starting offset: " . ($offset ?? 'auto (resume from last)') . "\n";
echo "Skip existing: " . ($skipExisting ? 'yes' : 'no') . "\n";
echo "\n";

try {
    $erpProvider = ProviderFactory::createErpProvider($providerName);
    $syncService = new CustomerSyncService($erpProvider);
    
    $result = $syncService->syncFromProvider($providerName, $limit, $offset, $skipExisting);
    
    echo "\nSynchronization completed!\n";
    echo "Synced: {$result['synced']}\n";
    echo "Skipped: {$result['skipped']}\n";
    echo "Errors: {$result['errors']}\n";
    echo "Current offset: {$result['offset']}\n";
    echo "Next offset: {$result['next_offset']}\n";
    echo "Batch size: {$result['batch_size']}\n";
    
    if ($result['batch_size'] > 0 && $result['next_offset'] > $result['offset']) {
        echo "\nTo continue syncing, run:\n";
        echo "  php cli/sync-customers.php --provider={$providerName} --limit={$limit}\n";
    }
    
    exit(0);
} catch (\Exception $e) {
    \App\Utils\LogHelper::critical('Customer sync failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
