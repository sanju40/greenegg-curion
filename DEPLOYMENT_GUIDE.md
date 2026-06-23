# Deployment Guide - Production Setup

This guide covers all steps needed to deploy and run the integration system on a production server.

## 📋 Pre-Deployment Checklist

### 1. Server Requirements
- PHP 8.1 or higher
- MySQL 5.7+ or MariaDB 10.3+
- cURL extension enabled
- PDO extension enabled
- JSON extension enabled
- Cron access (for scheduled tasks)

### 2. File Permissions
```bash
# Set proper permissions
chmod 755 cli/*.php
chmod 644 config/*.php
chmod 755 public/index.php
chmod -R 775 logs/
chmod 644 .env
```

---

## 🚀 Initial Setup Steps

### Step 1: Configure Environment Variables

Edit `.env` file with your production credentials:

```env
# Application
APP_ENV=production
APP_TIMEZONE=UTC

# Database
DB_HOST=localhost
DB_NAME=wws_shopify
DB_USER=your_db_user
DB_PASSWORD=your_db_password

# WwsRestService API
WWS_BASE_URL=https://your-api-url/datasnap/rest/TWwsServerMethods
WWS_DATABASE_ID=1
WWS_USERNAME=your_username
WWS_PASSWORD=your_password
WWS_VERIFY_SSL=false
WWS_TIMEOUT=60
WWS_CONNECT_TIMEOUT=30

# Shopify
SHOPIFY_SHOP_DOMAIN=your-shop.myshopify.com
SHOPIFY_ACCESS_TOKEN=your_access_token
SHOPIFY_API_VERSION=2026-01
SHOPIFY_WEBHOOK_SECRET=your_webhook_secret

# Features
WEB_ENABLED=true
CLI_ENABLED=true

# Logging
LOG_ENABLED=true
LOG_LEVEL=info

# Order Processing
ORDER_MAX_RETRIES=3
ORDER_RETRY_DELAY_BASE=5

# Sync Settings
PRODUCT_SYNC_ENABLED=true
CUSTOMER_SYNC_ENABLED=true
INVENTORY_SYNC_ENABLED=true
PRICE_SYNC_ENABLED=true
ORDER_PROCESSING_ENABLED=true
```

### Step 2: Run Database Migrations

```bash
# Navigate to project directory
cd /path/to/wws

# Run all migrations
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php
```

This will create all required database tables:
- `sync_logs` - Sync operation logs
- `api_logs` - API call logs
- `product_mappings` - Product mappings between systems
- `order_queue` - Order processing queue
- `customer_mappings` - Customer mappings
- `customer_sync_progress` - Customer sync pagination tracking
- `providers` - Provider registry
- `provider_capabilities` - Provider capabilities
- `job_queue` - Scheduled jobs
- `error_queue` - Error retry queue
- `audit_logs` - Audit trail
- `application_logs` - Application-level logs

### Step 3: Verify Configuration

```bash
# Test all connections
php cli/test-connection.php
# Or via web: https://your-domain.com/api/test-connection
```

---

## 📦 Initial Data Sync

### Step 4: Sync Products (Initial Import)

**Option A: Sync All Products**
```bash
# Sync all products from ERP to Shopify
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-products.php
```

**Option B: Sync Limited Products (Testing)**
```bash
# Sync first 10 products for testing
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-products.php --limit=10
```

**What this does:**
- Fetches products from WwsRestService API
- Checks if product exists in Shopify (by SKU)
- Creates new products or updates existing ones
- Sets product status to `draft` with `published_scope: global`
- Adds tags: `ERP-WWS` and `API_PRODUCTS`
- Sets default metafields for sync control

### Step 5: Sync Customers (Initial Import)

**Option A: Sync All Customers**
```bash
# Sync all customers from ERP to Shopify
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-customers.php
```

**Option B: Sync Limited Customers (Testing)**
```bash
# Sync first 10 customers for testing
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-customers.php --limit=10
```

**Option C: Sequential Sync with Pagination**
```bash
# Sync with pagination (resumes from last position)
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-customers.php --limit=100 --offset=auto

# Reset and start from beginning
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-customers.php --limit=100 --offset=0 --reset

# Skip existing customers (faster)
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-customers.php --limit=100 --skip-existing
```

**What this does:**
- Fetches customers from WwsRestService API
- Skips customers without email addresses
- Checks if customer exists in Shopify (by email)
- Creates new customers or updates existing ones
- Syncs customer addresses
- Adds tags: `ERP-WWS` and `API_CUSTOMERS`

### Step 6: Process Pending Orders (If Any)

```bash
# Process any pending orders in queue
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/process-pending-orders.php --limit=50
```

---

## ⏰ Cron Jobs Setup

### Recommended Cron Schedule

Add these to your crontab (`crontab -e`):

```bash
# ============================================
# WWS-SHOPIFY INTEGRATION CRON JOBS
# ============================================

# Main Scheduler (runs every minute - processes all scheduled jobs)
* * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/run-scheduler.php >> /dev/null 2>&1

# Order Processing (process pending orders every 2 minutes)
*/2 * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/process-pending-orders.php --limit=20 >> /dev/null 2>&1

# Error Queue Processor (process failed operations every 5 minutes)
*/5 * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/process-error-queue.php --limit=50 >> /dev/null 2>&1

# Retry Failed Syncs (retry failed sync operations every 15 minutes)
*/15 * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/retry-failed-syncs.php --limit=20 >> /dev/null 2>&1
```

### Alternative: Single Scheduler Approach

If you prefer to use only the scheduler (recommended):

```bash
# Main Scheduler (runs every minute - handles all scheduled tasks)
* * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/run-scheduler.php >> /dev/null 2>&1
```

The scheduler will automatically handle:
- Product sync (based on `PRODUCT_SYNC_INTERVAL`)
- Inventory sync (based on `INVENTORY_SYNC_INTERVAL`)
- Price sync (based on `PRICE_SYNC_INTERVAL`)
- Customer sync (based on `CUSTOMER_SYNC_INTERVAL`)
- Order processing (based on `ORDER_PROCESSING_INTERVAL`)
- Order status sync (based on `ORDER_STATUS_SYNC_INTERVAL`)

### Cron Job Configuration Details

**Main Scheduler (`run-scheduler.php`)**
- **Frequency:** Every minute
- **Purpose:** Executes all scheduled jobs from `config/scheduler.php`
- **What it does:**
  - Checks `job_queue` table for due jobs
  - Executes product sync, inventory sync, price sync, customer sync, order processing, order status sync
  - Reschedules recurring jobs

**Order Processing (`process-pending-orders.php`)**
- **Frequency:** Every 2 minutes (or let scheduler handle it)
- **Purpose:** Processes orders from `order_queue` table
- **What it does:**
  - Fetches pending orders
  - Routes orders to appropriate ERP providers
  - Creates transactions in ERP
  - Updates order tags in Shopify (`ERP_SYNCED`, `TRANSACTION_ID:xxx`)
  - Handles retries with exponential backoff

**Error Queue Processor (`process-error-queue.php`)**
- **Frequency:** Every 5 minutes (or let scheduler handle it)
- **Purpose:** Retries failed operations from `error_queue` table
- **What it does:**
  - Processes failed product syncs
  - Processes failed customer syncs
  - Processes failed order operations
  - Implements exponential backoff

**Retry Failed Syncs (`retry-failed-syncs.php`)**
- **Frequency:** Every 15 minutes (optional)
- **Purpose:** Retries failed sync operations from `sync_logs` table
- **What it does:**
  - Finds failed sync operations
  - Retries with updated data

---

## 🔧 Scheduler Configuration

The scheduler is configured in `config/scheduler.php`. Default intervals:

```php
'product_sync' => [
    'enabled' => true,
    'interval' => '12h',  // Every 12 hours
],

'inventory_sync' => [
    'enabled' => true,
    'interval' => '5m',  // Every 5 minutes
],

'price_sync' => [
    'enabled' => true,
    'interval' => '30m',  // Every 30 minutes
],

'customer_sync' => [
    'enabled' => true,
    'interval' => '1h',  // Every hour
],

'order_processing' => [
    'enabled' => true,
    'interval' => '2m',  // Every 2 minutes
],

'order_status_sync' => [
    'enabled' => true,
    'interval' => '5m',  // Every 5 minutes
],
```

**Customize via `.env`:**
```env
PRODUCT_SYNC_INTERVAL=12h
INVENTORY_SYNC_INTERVAL=5m
PRICE_SYNC_INTERVAL=30m
CUSTOMER_SYNC_INTERVAL=1h
ORDER_PROCESSING_INTERVAL=2m
ORDER_STATUS_SYNC_INTERVAL=5m
```

---

## 📊 Monitoring & Maintenance

### Check Logs

**Application Logs:**
```bash
# View error logs
tail -f logs/error.log

# View info logs
tail -f logs/info.log

# View all logs
tail -f logs/*.log
```

**Database Logs:**
```sql
-- Check sync logs
SELECT * FROM sync_logs ORDER BY created_at DESC LIMIT 50;

-- Check API logs
SELECT * FROM api_logs ORDER BY created_at DESC LIMIT 50;

-- Check order queue
SELECT * FROM order_queue WHERE status = 'pending' ORDER BY created_at ASC;

-- Check error queue
SELECT * FROM error_queue WHERE status = 'pending' ORDER BY next_retry_at ASC;

-- Check application logs
SELECT * FROM application_logs WHERE level IN ('error', 'critical') ORDER BY created_at DESC LIMIT 50;
```

### Manual Operations

**Sync Single Product:**
```bash
php cli/sync-product.php --sku=YOUR-SKU
# or
php cli/sync-product.php --id=PRODUCT-ID
```

**Sync Single Customer:**
```bash
php cli/sync-customer.php --email=customer@example.com
# or
php cli/sync-customer.php --id=CUSTOMER-ID
```

**Process Specific Order:**
```bash
php cli/process-pending-orders.php --limit=1
```

**Check Scheduler Status:**
```bash
php cli/run-scheduler.php
```

---

## 🔐 Security Checklist

1. ✅ Set proper file permissions (`.env` should be 644, not world-readable)
2. ✅ Ensure `.env` is in `.gitignore`
3. ✅ Use strong database passwords
4. ✅ Enable HTTPS for webhook endpoints
5. ✅ Verify webhook HMAC validation is working
6. ✅ Restrict web endpoint access if not needed (`WEB_ENABLED=false` in production)
7. ✅ Set `APP_ENV=production` in `.env`
8. ✅ Review and restrict database user permissions

---

## 🚨 Troubleshooting

### Scheduler Not Running

1. Check cron is running: `systemctl status cron` (Linux) or `crontab -l`
2. Check cron logs: `/var/log/cron` or `/var/log/syslog`
3. Test scheduler manually: `php cli/run-scheduler.php`
4. Check database connection
5. Verify file paths in cron jobs are absolute

### Orders Not Processing

1. Check order queue: `SELECT * FROM order_queue WHERE status = 'pending'`
2. Check error logs: `tail -f logs/error.log`
3. Manually process: `php cli/process-pending-orders.php --limit=1`
4. Check API credentials in `.env`
5. Verify webhook is receiving orders

### Products Not Syncing

1. Check sync logs: `SELECT * FROM sync_logs WHERE operation_type = 'product_sync' ORDER BY created_at DESC LIMIT 10`
2. Check API connection: `php cli/test-connection.php`
3. Verify product sync is enabled: Check `config/scheduler.php` and `.env`
4. Check for errors in `logs/error.log`

### High Memory Usage

1. Increase PHP memory limit in cron: `-dmemory_limit=512M`
2. Process in smaller batches: Use `--limit` parameter
3. Check for memory leaks in logs

---

## 📝 Quick Reference

### Initial Setup Commands
```bash
# 1. Run migrations
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php

# 2. Test connections
php cli/test-connection.php

# 3. Sync products (initial)
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-products.php

# 4. Sync customers (initial)
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/sync-customers.php --limit=100

# 5. Process pending orders
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/process-pending-orders.php
```

### Production Cron (Recommended)
```bash
# Single scheduler handles everything
* * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/run-scheduler.php >> /dev/null 2>&1
```

### Production Cron (Alternative - More Control)
```bash
# Scheduler for sync jobs
* * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/run-scheduler.php >> /dev/null 2>&1

# Order processing (every 2 min)
*/2 * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/process-pending-orders.php --limit=20 >> /dev/null 2>&1

# Error queue (every 5 min)
*/5 * * * * cd /path/to/wws && php -dmemory_limit=-1 -dmax_execution_time=-1 cli/process-error-queue.php --limit=50 >> /dev/null 2>&1
```

---

## ✅ Post-Deployment Verification

1. **Test Webhook:**
   - Place a test order in Shopify
   - Check `order_queue` table for new entry
   - Verify order is processed within 2 minutes
   - Check order tags in Shopify (`ERP_SYNCED`, `TRANSACTION_ID:xxx`)

2. **Test Product Sync:**
   - Check `sync_logs` table for product sync entries
   - Verify products appear in Shopify
   - Check product tags and metafields

3. **Test Customer Sync:**
   - Check `sync_logs` table for customer sync entries
   - Verify customers appear in Shopify
   - Check customer tags

4. **Monitor Logs:**
   - Check `logs/error.log` for any errors
   - Check `application_logs` table for critical errors
   - Monitor `api_logs` for API call success rates

---

## 📞 Support

For issues or questions:
1. Check logs first: `logs/error.log` and `application_logs` table
2. Review this deployment guide
3. Check `README.md` for general information
4. Review `SETUP.md` for setup details


