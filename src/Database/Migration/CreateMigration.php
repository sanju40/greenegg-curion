<?php

namespace App\Database\Migration;

/**
 * Create Migration File
 * Generates migration files with timestamp and model name
 */
class CreateMigration
{
    private $migrationsPath;

    public function __construct()
    {
        // Ensure BASE_PATH is defined
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
        }
        
        $this->migrationsPath = BASE_PATH . '/database/migrations';
        $this->createMigration();
    }

    private function createMigration()
    {
        $options = getopt("", ["model::"]);

        // Ensure model name is provided
        if (!isset($options['model']) || empty($options['model'])) {
            die("Error: Missing required parameter --model\nUsage: php create-migration.php --model=create_customers_table\n");
        }

        $migrationName = $options['model'];
        $utcTime = new \DateTime("now", new \DateTimeZone("UTC"));
        $timestamp = $utcTime->format('Ymd_His');

        // Construct the migration filename
        $filename = $this->migrationsPath . "/{$timestamp}_{$migrationName}.php";

        // Ensure the migrations directory exists
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0777, true);
        }

        // Migration file template
        $template = <<<PHP
<?php
return [
    'up' => "
-- Add your migration SQL here
-- Example:
-- CREATE TABLE IF NOT EXISTS example_table (
--     id INT AUTO_INCREMENT PRIMARY KEY,
--     name VARCHAR(255) NOT NULL,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- )
",
    'down' => "
-- Add your rollback SQL here
-- Example:
-- DROP TABLE IF EXISTS example_table
"
];
PHP;

        // Check if file already exists before creating
        if (!file_exists($filename)) {
            if (file_put_contents($filename, $template)) {
                echo "Migration file created: {$filename}\n";
            } else {
                echo "Failed to create migration file.\n";
            }
        } else {
            echo "Error: Migration file already exists: {$filename}\n";
        }
    }
}

