<?php

/**
 * CLI: Clean Database
 * Cleans sync_logs and api_logs tables for testing
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

if (isset($config['cli_enabled']) && !$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

try {
    $db = \App\Database\Database::getInstance()->getConnection();
    
    echo "Cleaning database...\n";
    
    // Clean sync_logs
    $stmt = $db->prepare("DELETE FROM sync_logs WHERE operation_type = 'product_sync'");
    $stmt->execute();
    $syncDeleted = $stmt->rowCount();
    echo "Deleted {$syncDeleted} records from sync_logs\n";
    
    // Clean api_logs
    $stmt = $db->prepare("DELETE FROM api_logs WHERE api_type IN ('wws', 'shopify')");
    $stmt->execute();
    $apiDeleted = $stmt->rowCount();
    echo "Deleted {$apiDeleted} records from api_logs\n";
    
    // Clean product_mappings
    $stmt = $db->prepare("DELETE FROM product_mappings");
    $stmt->execute();
    $mappingDeleted = $stmt->rowCount();
    echo "Deleted {$mappingDeleted} records from product_mappings\n";
    
    echo "\nDatabase cleaned successfully!\n";
    echo "Total deleted:\n";
    echo "  - sync_logs: {$syncDeleted}\n";
    echo "  - api_logs: {$apiDeleted}\n";
    echo "  - product_mappings: {$mappingDeleted}\n";
    
    exit(0);
} catch (\Exception $e) {
    \App\Utils\LogHelper::critical('Database cleanup failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

