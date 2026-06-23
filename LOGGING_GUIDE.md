# Logging System Guide

## Overview

The application uses a multi-level logging system with the following features:

- **Critical errors are always logged** (even if logging is disabled)
- **Standard log levels** (PSR-3 compatible)
- **Multiple output destinations** (files and database)
- **Organized log files** in `logs/` directory

## Log Levels

The system supports 8 log levels (from most to least critical):

1. **EMERGENCY** - System is unusable (always logged)
2. **ALERT** - Action must be taken immediately (always logged)
3. **CRITICAL** - Critical conditions (always logged)
4. **ERROR** - Runtime errors
5. **WARNING** - Warning conditions
6. **NOTICE** - Normal but significant condition
7. **INFO** - Informational messages
8. **DEBUG** - Debug-level messages

### Always Logged Levels

The following levels are **always logged** regardless of logging configuration:
- `EMERGENCY`
- `ALERT`
- `CRITICAL`

These critical errors will be logged even if `LOG_ENABLED=false` in your `.env` file.

## Usage

### Method 1: Using AppLogger (Recommended)

```php
use App\Utils\AppLogger;

$logger = new AppLogger();

// Critical error (always logged)
$logger->critical('Database connection failed', [
    'host' => $host,
    'error' => $errorMessage,
]);

// Error
$logger->error('API request failed', [
    'endpoint' => '/api/products',
    'status_code' => 500,
]);

// Warning
$logger->warning('Rate limit approaching', [
    'remaining' => 10,
]);

// Info
$logger->info('Product sync started', [
    'product_count' => 100,
]);

// Debug
$logger->debug('Processing item', [
    'item_id' => 123,
]);
```

### Method 2: Using LogHelper (Convenience)

```php
use App\Utils\LogHelper;

// Shorter syntax
LogHelper::critical('Critical error occurred');
LogHelper::error('Error message');
LogHelper::warning('Warning message');
LogHelper::info('Info message');
LogHelper::debug('Debug message');
```

## Configuration

Add to your `.env` file:

```env
# Logging Configuration
LOG_ENABLED=true              # Enable/disable logging (critical errors always logged)
LOG_LEVEL=info                # Minimum log level (emergency, alert, critical, error, warning, notice, info, debug)
LOG_FILE_MAX_SIZE=10485760     # Max log file size in bytes (10MB default)
LOG_RETENTION_DAYS=30          # Keep logs for N days
```

### Log Level Examples

- `LOG_LEVEL=critical` - Only critical errors
- `LOG_LEVEL=error` - Errors and critical
- `LOG_LEVEL=warning` - Warnings, errors, and critical
- `LOG_LEVEL=info` - Info, warnings, errors, and critical (default)
- `LOG_LEVEL=debug` - All levels

## Log Files

All logs are stored in the `logs/` directory:

- `logs/error.log` - Critical errors, alerts, emergencies, and regular errors
- `logs/warning.log` - Warning messages
- `logs/notice.log` - Notice messages
- `logs/info.log` - Informational messages
- `logs/debug.log` - Debug messages
- `logs/app.log` - General application log (fallback)

### Log File Format

```
[2025-01-15 10:30:45] [CRITICAL] Database connection failed | Context: {"host":"localhost","error":"Connection refused"}
[2025-01-15 10:30:46] [ERROR] API request failed | Context: {"endpoint":"/api/products","status_code":500}
[2025-01-15 10:30:47] [INFO] Product sync started | Context: {"product_count":100}
```

## Database Logging

Logs are also stored in the `application_logs` table:

```sql
SELECT * FROM application_logs 
WHERE level = 'CRITICAL' 
ORDER BY created_at DESC 
LIMIT 10;
```

## Migration

Run the migration to create the `application_logs` table:

```bash
php cli/migrate.php
```

## Best Practices

### When to Use Each Level

- **EMERGENCY**: System crash, database completely down
- **ALERT**: Security breach, payment processing failure
- **CRITICAL**: Database connection lost, API authentication failed
- **ERROR**: API request failed, sync operation failed
- **WARNING**: Rate limit approaching, deprecated feature used
- **NOTICE**: Important business events (order placed, customer created)
- **INFO**: General information (sync started, batch processed)
- **DEBUG**: Detailed debugging (variable values, execution flow)

### Context Data

Always include relevant context:

```php
// Good
$logger->error('Failed to sync product', [
    'product_id' => $productId,
    'sku' => $sku,
    'error' => $exception->getMessage(),
    'stack_trace' => $exception->getTraceAsString(),
]);

// Bad
$logger->error('Failed to sync product');
```

### Critical Errors

Use critical level for errors that require immediate attention:

```php
// Database connection failure
$logger->critical('Cannot connect to database', [
    'host' => $dbHost,
    'database' => $dbName,
    'error' => $e->getMessage(),
]);

// API authentication failure
$logger->critical('API authentication failed', [
    'provider' => 'shopify',
    'endpoint' => '/admin/api/products.json',
]);
```

## Examples

### Product Sync Service

```php
use App\Utils\LogHelper;

try {
    LogHelper::info('Starting product sync', ['limit' => $limit]);
    
    $product = $this->syncProduct($productId);
    
    LogHelper::info('Product synced successfully', [
        'product_id' => $productId,
        'shopify_id' => $product['id'],
    ]);
} catch (\Exception $e) {
    LogHelper::error('Product sync failed', [
        'product_id' => $productId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
}
```

### Order Processing

```php
use App\Utils\LogHelper;

try {
    LogHelper::info('Processing order', ['order_id' => $orderId]);
    
    // Process order...
    
    if ($criticalError) {
        LogHelper::critical('Order processing critical failure', [
            'order_id' => $orderId,
            'error' => $errorMessage,
        ]);
    }
} catch (\Exception $e) {
    LogHelper::error('Order processing failed', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
    ]);
}
```

## Log Rotation

Log files can grow large. Consider implementing log rotation:

1. **Manual rotation**: Archive old logs periodically
2. **Size-based rotation**: Rotate when file exceeds `LOG_FILE_MAX_SIZE`
3. **Time-based rotation**: Rotate daily/weekly

Example cleanup script:

```bash
# Remove logs older than 30 days
find logs/ -name "*.log" -mtime +30 -delete
```

## Monitoring

Monitor critical errors:

```sql
-- Count critical errors in last hour
SELECT COUNT(*) FROM application_logs 
WHERE level = 'CRITICAL' 
AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- Get recent critical errors
SELECT * FROM application_logs 
WHERE level IN ('CRITICAL', 'ALERT', 'EMERGENCY')
ORDER BY created_at DESC 
LIMIT 20;
```

## Troubleshooting

### Logs not appearing

1. Check `LOG_ENABLED` in `.env`
2. Check `logs/` directory permissions
3. Check database connection for DB logging
4. Verify log level setting

### Critical errors not logged

Critical errors should always be logged. If they're not:
1. Check `logs/` directory exists and is writable
2. Check disk space
3. Check file permissions

### Too many logs

1. Increase `LOG_LEVEL` (e.g., from `debug` to `info`)
2. Implement log rotation
3. Review and remove unnecessary debug logs

