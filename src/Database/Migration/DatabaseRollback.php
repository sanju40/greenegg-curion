<?php

namespace App\Database\Migration;

use App\Database\Database;

/**
 * Database Migration Rollback
 * Rolls back migrations by batch or specific file
 */
class DatabaseRollback
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
        
        $this->rollbackTables();
    }

    /**
     * Rollback migrations
     */
    private function rollbackTables()
    {
        $options = getopt("", ["step::", "path::"]);
        
        if (isset($options['path'])) {
            // Rollback a specific migration file
            $migrationName = basename($options['path']);

            $stmt = $this->db->prepare("SELECT batch FROM migrations WHERE migration = ?");
            $stmt->execute([$migrationName]);
            $batch = $stmt->fetchColumn();

            if (!$batch) {
                echo "Migration not found: $migrationName\n";
                exit(1);
            }
            
            $this->rollbackMigrations([$migrationName]);
        } else {
            // Rollback by batch (default: last batch, or --step=N)
            $step = isset($options['step']) ? (int)$options['step'] : 1;

            for ($i = 0; $i < $step; $i++) {
                $batch = $this->db->query("SELECT MAX(batch) FROM migrations")->fetchColumn();
                
                if (!$batch) {
                    echo "No more migrations to rollback.\n";
                    exit(0);
                }

                $stmt = $this->db->prepare("SELECT migration FROM migrations WHERE batch = ?");
                $stmt->execute([$batch]);
                $migrations = $stmt->fetchAll(\PDO::FETCH_COLUMN);

                if (empty($migrations)) {
                    echo "No migrations found in batch $batch.\n";
                    exit(0);
                }

                $this->rollbackMigrations($migrations);
            }
        }
        
        echo "Rollback complete!\n";
    }

    /**
     * Execute rollback for specific migrations
     */
    private function rollbackMigrations($migrations)
    {
        foreach (array_reverse($migrations) as $migrationName) {
            $file = $this->migrationsPath . '/' . $migrationName;
            
            if (file_exists($file)) {
                try {
                    $migration = require $file;

                    if (!isset($migration['down'])) {
                        echo "Error: Migration file '$migrationName' missing 'down' key. Skipping.\n";
                        continue;
                    }

                    // Execute the rollback SQL query
                    $this->db->exec($migration['down']);

                    // Remove migration record from database
                    $stmt = $this->db->prepare("DELETE FROM migrations WHERE migration = ?");
                    $stmt->execute([$migrationName]);

                    echo "Rolled back: $migrationName\n";
                } catch (\Exception $e) {
                    \App\Utils\LogHelper::error('Rollback failed', [
                        'migration' => $migrationName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    echo "Error rolling back '$migrationName': " . $e->getMessage() . "\n";
                    // Continue with next migration
                }
            } else {
                echo "Migration file not found: $migrationName\n";
            }
        }
    }
}

