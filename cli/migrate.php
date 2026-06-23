<?php

/**
 * CLI: Run Database Migrations
 * Usage: 
 *   php cli/migrate.php
 *   php cli/migrate.php --path=migrations/20250214_120000_create_users_table.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database\Database;
use App\Database\Migration\DatabaseSetup;

// Only run this part if the file is executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    try {
        $database = Database::getInstance();
        $databaseSetup = new DatabaseSetup($database);
    } catch (\Exception $e) {
        \App\Utils\LogHelper::critical('Database migration failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        echo 'Error: ' . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    ?>
    <details open>
        <summary><strong>Error</strong>: Direct Access Not Allowed!!</summary>
        <p><strong>Run commands as following:</strong></p>
        <p><code>php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php</code></p>
        <p><code>php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php --path=migrations/filename.php</code></p>
    </details>
    <?php
}

