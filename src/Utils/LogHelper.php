<?php

namespace App\Utils;

/**
 * Log Helper
 * Convenience functions for logging
 * Provides global access to AppLogger
 */
class LogHelper
{
    private static $logger = null;

    /**
     * Get logger instance
     */
    private static function getLogger(): AppLogger
    {
        if (self::$logger === null) {
            self::$logger = new AppLogger();
        }
        return self::$logger;
    }

    /**
     * Log emergency
     */
    public static function emergency(string $message, array $context = []): void
    {
        self::getLogger()->emergency($message, $context);
    }

    /**
     * Log alert
     */
    public static function alert(string $message, array $context = []): void
    {
        self::getLogger()->alert($message, $context);
    }

    /**
     * Log critical (always logged)
     */
    public static function critical(string $message, array $context = []): void
    {
        self::getLogger()->critical($message, $context);
    }

    /**
     * Log error
     */
    public static function error(string $message, array $context = []): void
    {
        self::getLogger()->error($message, $context);
    }

    /**
     * Log warning
     */
    public static function warning(string $message, array $context = []): void
    {
        self::getLogger()->warning($message, $context);
    }

    /**
     * Log notice
     */
    public static function notice(string $message, array $context = []): void
    {
        self::getLogger()->notice($message, $context);
    }

    /**
     * Log info
     */
    public static function info(string $message, array $context = []): void
    {
        self::getLogger()->info($message, $context);
    }

    /**
     * Log debug
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getLogger()->debug($message, $context);
    }
}

