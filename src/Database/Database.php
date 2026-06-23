<?php

namespace App\Database;

use App\Utils\LogHelper;
use PDO;
use PDOException;

/**
 * Database Connection Singleton
 */
class Database
{
    private static $instance = null;
    private $connection = null;
    private $config = null;

    private function __construct()
    {
        // Try to get config from Core\Config first, fallback to global config
        try {
            $this->config = \App\Core\Config::get();
        } catch (\Exception $e) {
            // Fallback to global config if Core\Config not available
            $this->config = $GLOBALS['app_config'] ?? null;
        }
        
        // If still no config, try loading directly
        if (!$this->config) {
            if (defined('BASE_PATH')) {
                $configFile = BASE_PATH . '/config/config.php';
                if (file_exists($configFile)) {
                    $this->config = require $configFile;
                }
            }
        }
        
        if (!$this->config || !is_array($this->config)) {
            LogHelper::critical('Database configuration not found', []);
            throw new \Exception('Database configuration not found. Please ensure config/config.php is loaded and contains a database configuration.');
        }
        
        $this->connect();
    }

    /**
     * Get database instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get PDO connection
     * Automatically reconnects if the server dropped the connection (error 2006).
     */
    public function getConnection()
    {
        if ($this->connection === null) {
            $this->connect();
            return $this->connection;
        }

        // Ping to detect stale connection ("MySQL server has gone away", error 2006).
        // This happens on long-running CLI processes when MySQL drops the idle connection.
        try {
            $this->connection->query('SELECT 1');
        } catch (\PDOException $e) {
            LogHelper::warning('Database connection lost, reconnecting', [
                'error' => $e->getMessage(),
            ]);
            $this->connection = null;
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * Establish database connection
     */
    private function connect()
    {
        if (!$this->config || !isset($this->config['database'])) {
            LogHelper::critical('Database configuration not found', []);
            throw new \Exception('Database configuration not found. Please check your config/config.php file.');
        }
        
        $dbConfig = $this->config['database'];
        
        if (!isset($dbConfig['host']) || !isset($dbConfig['name']) || !isset($dbConfig['user'])) {
            LogHelper::critical('Database configuration incomplete', [
                'missing_fields' => array_diff(['host', 'name', 'user', 'password'], array_keys($dbConfig)),
            ]);
            throw new \Exception('Database configuration incomplete. Required: host, name, user, password');
        }
        
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $dbConfig['host'],
            $dbConfig['name'],
            $dbConfig['charset'] ?? 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Reset server-side wait_timeout to 8h so long syncs don't lose the connection
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=28800",
        ];

        try {
            $this->connection = new PDO(
                $dsn,
                $dbConfig['user'],
                $dbConfig['password'] ?? '',
                $options
            );
        } catch (PDOException $e) {
            LogHelper::critical('Database connection failed', [
                'host' => $dbConfig['host'] ?? 'unknown',
                'database' => $dbConfig['name'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Begin transaction
     */
    public function beginTransaction()
    {
        return $this->getConnection()->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit()
    {
        return $this->getConnection()->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback()
    {
        return $this->getConnection()->rollBack();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        LogHelper::critical('Attempted to unserialize Database singleton', []);
        throw new \Exception("Cannot unserialize singleton");
    }
}

