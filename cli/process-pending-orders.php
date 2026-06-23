<?php

/**
 * CLI: Process Pending Orders
 * Usage: php cli/process-pending-orders.php [--limit=10] [--dry-run] [--live]
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

if (!$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', ['limit:', 'dry-run', 'live']);

$limit = isset($options['limit']) ? (int)$options['limit'] : 10;
$configDryRun = (bool) ($config['order_processing']['dry_run'] ?? false);
$dryRun = isset($options['live']) ? false : (isset($options['dry-run']) || $configDryRun);

echo "Processing pending orders...\n";
echo "Limit: {$limit}\n";
echo "Mode: " . ($dryRun ? "DRY-RUN (no WWS/Curion writes)" : "LIVE") . "\n\n";

try {
    $orderQueueRepository = new \App\Database\Repository\OrderQueueRepository();
    $orderProcessingService = new \App\Core\Services\OrderProcessingService(null, null, null, $dryRun);
    
    $pendingOrders = $orderQueueRepository->getPendingOrders($limit);
    
    if (empty($pendingOrders)) {
        echo "No pending orders found.\n";
        exit(0);
    }
    
    echo "Found " . count($pendingOrders) . " pending order(s)\n\n";
    
    $processed = 0;
    $failed = 0;
    
    foreach ($pendingOrders as $order) {
        $retryInfo = '';
        if (isset($order['retry_count']) && $order['retry_count'] > 0) {
            $retryInfo = " (Retry #{$order['retry_count']})";
        }
        echo "Processing order: {$order['shopify_order_number']} (ID: {$order['shopify_order_id']}){$retryInfo}... ";
        
        try {
            $orderData = json_decode($order['order_data'], true);
            if (!$orderData) {
                echo "Failed: Invalid order data\n";
                $failed++;
                continue;
            }
            
            $currentRetryCount = (int)($order['retry_count'] ?? 0);
            $result = $orderProcessingService->processOrder($orderData, $currentRetryCount);
            
            // Get primary transaction ID from results
            $primaryResult = !empty($result['results']) ? reset($result['results']) : null;
            if (($result['status'] ?? '') === 'dry_run') {
                echo "Dry-run preview stored (see order_queue.provider_payload / logs)\n";
            } else {
                $primaryTransactionId = $primaryResult['transaction_id'] ?? 'N/A';
                echo "Success! Transaction ID: {$primaryTransactionId}\n";
            }
            $processed++;
        } catch (\App\Exceptions\SyncException $e) {
            // Check if it's a retryable error (not max retries exceeded)
            $orderQueue = $orderQueueRepository->findByShopifyOrderId($order['shopify_order_id']);
            $retryCount = $orderQueue ? (int)($orderQueue['retry_count'] ?? 0) : 0;
            
            if ($retryCount < 3) { // Assuming max retries is 3
                echo "Failed (will retry): " . $e->getMessage() . "\n";
                // Order is still pending and will be retried
            } else {
                echo "Failed (max retries exceeded): " . $e->getMessage() . "\n";
                $failed++;
            }
        } catch (\Exception $e) {
            \App\Utils\LogHelper::error('Order processing failed with unexpected error', [
                'order_id' => $order['shopify_order_id'] ?? null,
                'order_number' => $order['shopify_order_number'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            echo "Failed: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
    
    echo "\nCompleted!\n";
    echo "Processed: {$processed}\n";
    echo "Failed: {$failed}\n";
    
    exit(0);
} catch (\Exception $e) {
    \App\Utils\LogHelper::critical('Order processing service failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

