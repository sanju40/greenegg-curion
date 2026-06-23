<?php

/**
 * CLI: Process Error Queue
 * Usage: php cli/process-error-queue.php [--limit=50]
 * This should be run via cron every 5-10 minutes
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

if (!$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', ['limit:']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 50;

try {
    $processor = new \App\Core\ErrorQueue\ErrorQueueProcessor();
    $stats = $processor->processPendingErrors($limit);
    
    echo "Error queue processed!\n";
    echo "Processed: {$stats['processed']}\n";
    echo "Resolved: {$stats['resolved']}\n";
    echo "Failed: {$stats['failed']}\n";
    
    exit(0);
} catch (\Exception $e) {
    \App\Utils\LogHelper::critical('Error queue processor failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

