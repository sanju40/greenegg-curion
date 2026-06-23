<?php

namespace App\Utils;

/**
 * Log Levels
 * PSR-3 compatible log levels
 */
class LogLevel
{
    const EMERGENCY = 'emergency';  // System is unusable
    const ALERT     = 'alert';      // Action must be taken immediately
    const CRITICAL  = 'critical';   // Critical conditions (always logged)
    const ERROR     = 'error';      // Runtime errors
    const WARNING   = 'warning';    // Warning conditions
    const NOTICE    = 'notice';     // Normal but significant condition
    const INFO      = 'info';       // Informational messages
    const DEBUG     = 'debug';      // Debug-level messages

    /**
     * Get numeric priority for level (lower = more important)
     */
    public static function getPriority(string $level): int
    {
        $priorities = [
            self::EMERGENCY => 0,
            self::ALERT     => 1,
            self::CRITICAL  => 2,
            self::ERROR     => 3,
            self::WARNING   => 4,
            self::NOTICE    => 5,
            self::INFO      => 6,
            self::DEBUG     => 7,
        ];

        return $priorities[strtolower($level)] ?? 6;
    }

    /**
     * Check if level should always be logged (critical errors)
     */
    public static function isAlwaysLogged(string $level): bool
    {
        $alwaysLogged = [
            self::EMERGENCY,
            self::ALERT,
            self::CRITICAL,
        ];

        return in_array(strtolower($level), $alwaysLogged);
    }

    /**
     * Get all valid levels
     */
    public static function getAllLevels(): array
    {
        return [
            self::EMERGENCY,
            self::ALERT,
            self::CRITICAL,
            self::ERROR,
            self::WARNING,
            self::NOTICE,
            self::INFO,
            self::DEBUG,
        ];
    }
}

