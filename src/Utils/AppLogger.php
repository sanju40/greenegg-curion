<?php

namespace App\Utils;

use App\Database\Database;
use PDO;

/**
 * Application Logger
 * Multi-level logging system with critical errors always logged
 * PSR-3 compatible interface
 */
class AppLogger
{
    private $db;
    private $config;
    private $logsPath;
    private $enabled;
    private $minLevel;

    // File handles for different log files
    private $fileHandles = [];

    public function __construct()
    {
        $this->config = \App\Core\Config::get();
        $this->logsPath = BASE_PATH . '/logs';
        $this->enabled = $this->config['logging']['enabled'] ?? true;
        $this->minLevel = $this->config['logging']['min_level'] ?? LogLevel::INFO;

        // Ensure logs directory exists
        if (!is_dir($this->logsPath)) {
            mkdir($this->logsPath, 0755, true);
        }

        // Try to get database connection (may fail if DB not available)
        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (\Exception $e) {
            $this->db = null;
        }
    }

    /**
     * Log emergency: System is unusable
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    /**
     * Log alert: Action must be taken immediately
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    /**
     * Log critical: Critical conditions (always logged)
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    /**
     * Log error: Runtime errors
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    /**
     * Log warning: Warning conditions
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * Log notice: Normal but significant condition
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    /**
     * Log info: Informational messages
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    /**
     * Log debug: Debug-level messages
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Main log method
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        $shouldLog = $this->shouldLog($level);

        if (!$shouldLog) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logData = [
            'timestamp' => $timestamp,
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
        ];

        // Always log critical errors (even if logging is disabled)
        $alwaysLog = LogLevel::isAlwaysLogged($level);

        // Log to file
        $this->logToFile($level, $logData, $alwaysLog);

        // Log to database (if enabled and DB available)
        if ($this->enabled || $alwaysLog) {
            $this->logToDatabase($level, $logData);
        }
    }

    /**
     * Check if level should be logged
     */
    private function shouldLog(string $level): bool
    {
        // Always log critical errors
        if (LogLevel::isAlwaysLogged($level)) {
            return true;
        }

        // Check if logging is enabled
        if (!$this->enabled) {
            return false;
        }

        // Check minimum level
        $levelPriority = LogLevel::getPriority($level);
        $minPriority = LogLevel::getPriority($this->minLevel);

        return $levelPriority <= $minPriority;
    }

    /**
     * Log to file
     */
    private function logToFile(string $level, array $logData, bool $alwaysLog): void
    {
        try {
            // Determine log file based on level
            $logFile = $this->getLogFile($level);
            $filePath = $this->logsPath . '/' . $logFile;

            // Format log entry
            $entry = $this->formatLogEntry($logData);

            // Write to file
            $handle = $this->getFileHandle($filePath);
            if ($handle) {
                fwrite($handle, $entry . PHP_EOL);
                fflush($handle); // Ensure immediate write
            } else {
                // Fallback: use file_put_contents
                file_put_contents($filePath, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
        } catch (\Exception $e) {
            // If file logging fails, try to log to PHP's error_log (last resort)
            // This is a fallback for critical errors when our logging system itself fails
            @error_log("AppLogger: Failed to write to log file: " . $e->getMessage());
        }
    }

    /**
     * Log to database
     */
    private function logToDatabase(string $level, array $logData): void
    {
        if (!$this->db) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO application_logs 
                (level, message, context, created_at)
                VALUES (?, ?, ?, NOW())
            ");

            $contextJson = !empty($logData['context']) 
                ? json_encode($logData['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            $stmt->execute([
                strtoupper($level),
                $logData['message'],
                $contextJson,
            ]);
        } catch (\Exception $e) {
            // If DB logging fails, log to file as fallback
            // Use PHP's error_log as last resort
            @error_log("AppLogger: Failed to log to database: " . $e->getMessage());
            // Try to log to file as fallback
            try {
                $this->logToFile($level, $logData, true);
            } catch (\Exception $fileException) {
                // If both DB and file logging fail, use PHP's error_log
                @error_log("AppLogger: Both DB and file logging failed: " . $fileException->getMessage());
            }
        }
    }

    /**
     * Get log file name based on level
     */
    private function getLogFile(string $level): string
    {
        $level = strtolower($level);

        // Critical errors go to error.log
        if (LogLevel::isAlwaysLogged($level)) {
            return 'error.log';
        }

        // Other levels go to their respective files
        $fileMap = [
            LogLevel::ERROR   => 'error.log',
            LogLevel::WARNING => 'warning.log',
            LogLevel::NOTICE  => 'notice.log',
            LogLevel::INFO    => 'info.log',
            LogLevel::DEBUG   => 'debug.log',
        ];

        return $fileMap[$level] ?? 'app.log';
    }

    /**
     * Get file handle (with caching)
     * @param string $filePath
     * @return resource|false|null
     */
    private function getFileHandle(string $filePath)
    {
        if (!isset($this->fileHandles[$filePath])) {
            $handle = @fopen($filePath, 'a');
            if ($handle) {
                $this->fileHandles[$filePath] = $handle;
            } else {
                return null;
            }
        }

        return $this->fileHandles[$filePath] ?? null;
    }

    /**
     * Format log entry
     */
    private function formatLogEntry(array $logData): string
    {
        $timestamp = $logData['timestamp'];
        $level = $logData['level'];
        $message = $logData['message'];
        $context = $logData['context'];

        $entry = "[{$timestamp}] [{$level}] {$message}";

        if (!empty($context)) {
            $contextStr = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $entry .= " | Context: {$contextStr}";
        }

        return $entry;
    }

    /**
     * Close all file handles
     */
    public function __destruct()
    {
        foreach ($this->fileHandles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}

