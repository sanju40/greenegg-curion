<?php

namespace App\Database\Migration;

use App\Database\Database;

/**
 * Database Migration Setup
 * Runs migrations in order and tracks them in the database
 */
class DatabaseSetup
{
    private $db;
    private $migrationsPath;

    public function __construct(Database $database)
    {
        $this->db = $database->getConnection();
        
        // Ensure BASE_PATH is defined
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(dirname(dirname(__DIR__))));
        }
        
        $this->migrationsPath = BASE_PATH . '/database/migrations';
        
        if (!$this->db) {
            echo "Failed to connect to the database.\n";
            exit(1);
        } else {
            echo "Connected to the database successfully.\n";
        }

        $this->createMigrationsTable();
        $this->migrateTables();
    }

    /**
     * Create the migrations table if it doesn't exist
     */
    private function createMigrationsTable()
    {
        $createTableQuery = "
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INT NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_migration (migration),
                INDEX idx_batch (batch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        try {
            $this->db->exec($createTableQuery);
            echo "Migrations table is ready.\n";
        } catch (\Exception $e) {
            \App\Utils\LogHelper::critical('Failed to create migrations table', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            echo 'Error creating migrations table: ' . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * Run migrations
     */
    private function migrateTables()
    {
        $options = getopt("", ["path::"]);

        // Get next batch number
        $batch = $this->db->query("SELECT MAX(batch) FROM migrations")->fetchColumn();
        $batch = $batch ? (int)$batch + 1 : 1;
        
        // Get migration files
        if (isset($options['path']) && $options['path']) {
            $path = $options['path'];
            // Handle both relative and absolute paths
            if (!file_exists($path)) {
                $path = $this->migrationsPath . '/' . basename($path);
            }
            $files = file_exists($path) ? [$path] : [];
        } else {
            $files = glob($this->migrationsPath . '/*.php');
            sort($files);
        }

        if (empty($files)) {
            echo "No migration files found.\n";
            return;
        }

        $migratedCount = 0;
        foreach ($files as $file) {
            $migrationName = basename($file);

            // Check if migration has already run
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM migrations WHERE migration = ?");
            $stmt->execute([$migrationName]);
            
            if ($stmt->fetchColumn() > 0) {
                echo "Skipped (already migrated): $migrationName\n";
                continue;
            }

            if (file_exists($file)) {
                try {
                    // Run the migration
                    $migration = require $file;
                    
                    if (!isset($migration['up'])) {
                        echo "Error: Migration file '$migrationName' missing 'up' key.\n";
                        continue;
                    }

                    // Execute migration SQL
                    $this->db->exec($migration['up']);

                    // Record migration
                    $stmt = $this->db->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
                    $stmt->execute([$migrationName, $batch]);

                    echo "Migrated: $migrationName\n";
                    $migratedCount++;
                } catch (\Exception $e) {
                    \App\Utils\LogHelper::error('Migration failed', [
                        'migration' => $migrationName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    echo "Error migrating '$migrationName': " . $e->getMessage() . "\n";
                    // Continue with next migration
                }
            } else {
                echo "Migration file not found: $migrationName\n";
            }
        }

        if ($migratedCount > 0) {
            echo "All migrations executed successfully! ($migratedCount migrated)\n";
        } else {
            echo "No new migrations to run.\n";
        }
    }
}

