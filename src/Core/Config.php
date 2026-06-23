<?php

namespace App\Core;

/**
 * Configuration Helper
 * Provides centralized access to configuration
 * Prevents duplicate config loading
 */
class Config
{
    private static $config = null;

    /**
     * Get configuration array
     */
    public static function get($key = null, $default = null)
    {
        if (self::$config === null) {
            // First try to get from GLOBALS (set by bootstrap.php)
            if (isset($GLOBALS['app_config']) && is_array($GLOBALS['app_config'])) {
                self::$config = $GLOBALS['app_config'];
            } else {
                // Fallback: load config file directly
                if (!defined('BASE_PATH')) {
                    throw new \Exception('BASE_PATH is not defined. Make sure bootstrap.php is loaded first.');
                }
                $configFile = BASE_PATH . '/config/config.php';
                if (!file_exists($configFile)) {
                    throw new \Exception("Config file not found: {$configFile}");
                }
                self::$config = require $configFile;
                
                if (!is_array(self::$config)) {
                    throw new \Exception('Config file did not return an array. Check config/config.php');
                }
            }
        }
        
        if ($key === null) {
            return self::$config;
        }

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (is_array($value) && isset($value[$k])) {
                $value = $value[$k];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * Check if config key exists
     */
    public static function has($key)
    {
        if (self::$config === null) {
            self::$config = require BASE_PATH . '/config/config.php';
        }

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (is_array($value) && isset($value[$k])) {
                $value = $value[$k];
            } else {
                return false;
            }
        }

        return true;
    }
}

