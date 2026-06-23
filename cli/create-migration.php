<?php

/**
 * CLI: Create Migration File
 * Usage: php cli/create-migration.php --model=create_customers_table
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database\Migration\CreateMigration;

// Only run this part if the file is executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    try {
        $createMigration = new CreateMigration();
    } catch (\Exception $e) {
        \App\Utils\LogHelper::critical('Create migration failed', [
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
        <p><code>php -dmemory_limit=-1 -dmax_execution_time=-1 cli/create-migration.php --model=create_customers_table</code></p>
    </details>
    <?php
}

