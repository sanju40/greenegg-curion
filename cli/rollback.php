<?php

/**
 * CLI: Rollback Database Migrations
 * Usage:
 *   php cli/rollback.php --step=2
 *   php cli/rollback.php --path=migrations/20250214_120000_create_users_table.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database\Database;
use App\Database\Migration\DatabaseRollback;

// Only run this part if the file is executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    try {
        $database = Database::getInstance();
        $databaseRollback = new DatabaseRollback($database);
    } catch (\Exception $e) {
        \App\Utils\LogHelper::critical('Database rollback failed', [
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
        <p><code>php -dmemory_limit=-1 -dmax_execution_time=-1 cli/rollback.php --step=2</code></p>
        <p><code>php -dmemory_limit=-1 -dmax_execution_time=-1 cli/rollback.php --path=migrations/filename.php</code></p>
    </details>
    <?php
}

