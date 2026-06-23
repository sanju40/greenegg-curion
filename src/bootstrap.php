<?php

/**
 * Bootstrap File
 * Autoloader and initialization
 */

// Define base path
define('BASE_PATH', dirname(__DIR__));

// Simple autoloader
spl_autoload_register(function ($class) {
    // Remove namespace prefix
    $class = str_replace('App\\', '', $class);
    
    // Convert namespace separators to directory separators
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', $class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Load configuration (cached, safe to call multiple times)
$config = require BASE_PATH . '/config/config.php';

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Error reporting
if ($config['app']['environment'] === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Make config available globally (for backward compatibility during transition)
if (!isset($GLOBALS['app_config'])) {
    $GLOBALS['app_config'] = $config;
}

