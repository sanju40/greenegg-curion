<?php

/**
 * CLI: Retry Failed Syncs
 * Usage: php cli/retry-failed-syncs.php [--type=product_sync] [--limit=10]
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

if (!$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', ['type:', 'limit:']);

$type = $options['type'] ?? null;
$limit = isset($options['limit']) ? (int)$options['limit'] : 10;

echo "Retrying failed syncs...\n";
echo "Type: " . ($type ?? 'all') . "\n";
echo "Limit: {$limit}\n\n";

try {
    $logger = new \App\Utils\Logger();
    $failedLogs = $logger->getSyncLogs($limit, 'failed', $type);
    
    if (empty($failedLogs)) {
        echo "No failed syncs found.\n";
        exit(0);
    }
    
    echo "Found " . count($failedLogs) . " failed sync(s)\n\n";
    
    $retried = 0;
    $stillFailed = 0;
    
    foreach ($failedLogs as $log) {
        echo "Retrying {$log['operation_type']} for {$log['entity_type']} ID: {$log['entity_id']}... ";
        
        try {
            // Increment retry count
            $logger->incrementRetryCount($log['id']);
            
            // Retry based on operation type
            if ($log['operation_type'] === 'product_sync') {
                $syncService = new \App\Core\Services\ProductSyncService();
                if ($log['entity_type'] === 'product') {
                    $syncService->syncProduct($log['entity_id']);
                }
            } elseif ($log['operation_type'] === 'order_processing') {
                $orderProcessingService = new \App\Core\Services\OrderProcessingService();
                $orderData = json_decode($log['request_data'], true);
                $orderProcessingService->processOrder($orderData);
            }
            
            echo "Success!\n";
            $retried++;
        } catch (\Exception $e) {
            \App\Utils\LogHelper::error('Retry failed', [
                'operation_type' => $log['operation_type'] ?? null,
                'entity_type' => $log['entity_type'] ?? null,
                'entity_id' => $log['entity_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            echo "Still failed: " . $e->getMessage() . "\n";
            $stillFailed++;
        }
    }
    
    echo "\nCompleted!\n";
    echo "Retried successfully: {$retried}\n";
    echo "Still failed: {$stillFailed}\n";
    
    exit(0);
} catch (\Exception $e) {
    \App\Utils\LogHelper::critical('Retry failed syncs service failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

