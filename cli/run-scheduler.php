<?php

/**
 * CLI: Run Scheduler
 * Usage: php cli/run-scheduler.php
 * This should be run via cron every minute
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

if (!$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

try {
    $scheduler = new \App\Core\Scheduler\SchedulerService();
    
    // Initialize jobs if they don't exist (first run)
    $scheduler->initializeJobs();
    
    // Run scheduled jobs
    $stats = $scheduler->runScheduledJobs();
    
    echo "Scheduler executed!\n";
    echo "Processed: {$stats['processed']}\n";
    echo "Succeeded: {$stats['succeeded']}\n";
    echo "Failed: {$stats['failed']}\n";
    
    exit(0);
} catch (\Exception $e) {
    \App\Utils\LogHelper::critical('Scheduler service failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

