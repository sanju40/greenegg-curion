# Clean Database and Run Migrations

## Overview

This guide explains how to clean your database and run all migrations from scratch. This is useful when:
- Setting up a fresh database
- Resetting development environment
- Testing migrations
- Recreating schema after changes

## ⚠️ WARNING

**This will delete ALL data in your database!** Only do this in development or if you have backups.

## Steps

### 1. Backup Your Data (Optional but Recommended)

```bash
# Backup database
mysqldump -u root -p wws_shopify > backup_$(date +%Y%m%d_%H%M%S).sql
```

### 2. Clean the Database

You have two options:

#### Option A: Drop and Recreate Database (Recommended)

```sql
-- Connect to MySQL
mysql -u root -p

-- Drop the database
DROP DATABASE IF EXISTS wws_shopify;

-- Recreate it
CREATE DATABASE wws_shopify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Exit MySQL
EXIT;
```

#### Option B: Drop All Tables

```sql
-- Connect to MySQL
mysql -u root -p wws_shopify

-- Drop all tables (in reverse dependency order)
DROP TABLE IF EXISTS migrations;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS error_queue;
DROP TABLE IF EXISTS job_queue;
DROP TABLE IF EXISTS provider_capabilities;
DROP TABLE IF EXISTS providers;
DROP TABLE IF EXISTS customer_sync_progress;
DROP TABLE IF EXISTS customer_mappings;
DROP TABLE IF EXISTS order_queue;
DROP TABLE IF EXISTS product_mappings;
DROP TABLE IF EXISTS api_logs;
DROP TABLE IF EXISTS sync_logs;

-- Exit MySQL
EXIT;
```

### 3. Run All Migrations

```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php
```

This will:
1. Create the `migrations` table
2. Run all migrations in order:
   - `20250101_000000_create_initial_schema.php` - Core tables
   - `20250101_000001_create_customer_sync_progress_table.php` - Customer sync progress
   - `20250101_000002_create_providers_schema.php` - Provider tables
   - `20250101_000003_enhance_tables_with_provider_columns.php` - Table enhancements

### 4. Verify Migration Status

```sql
-- Check which migrations ran
SELECT * FROM migrations ORDER BY batch, executed_at;

-- Verify tables exist
SHOW TABLES;
```

## Migration Order

Migrations are executed in this order:

1. **20250101_000000_create_initial_schema.php**
   - Creates: `sync_logs`, `api_logs`, `product_mappings`, `order_queue`, `customer_mappings`

2. **20250101_000001_create_customer_sync_progress_table.php**
   - Creates: `customer_sync_progress`

3. **20250101_000002_create_providers_schema.php**
   - Creates: `providers`, `provider_capabilities`, `job_queue`, `error_queue`, `audit_logs`

4. **20250101_000003_enhance_tables_with_provider_columns.php**
   - Enhances: `product_mappings`, `api_logs`, `sync_logs` with provider-related columns

## Troubleshooting

### Migration fails with "Table already exists"

This means the table already exists. Either:
1. Clean the database first (see Step 2)
2. Or the migration has already run (check `migrations` table)

### Migration fails with "Column already exists"

This happens when trying to add a column that already exists. The migration system will continue with other migrations. You can:
1. Clean the database and re-run
2. Or manually remove the column if it's safe

### Foreign key constraint errors

Make sure to drop tables in the correct order (dependent tables first). The migration system handles this automatically when running migrations.

## Quick Clean Script

You can create a script to automate the clean process:

```bash
#!/bin/bash
# clean-db.sh

DB_NAME="wws_shopify"
DB_USER="root"

echo "⚠️  WARNING: This will delete all data in $DB_NAME!"
read -p "Are you sure? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "Aborted."
    exit 1
fi

echo "Dropping database..."
mysql -u $DB_USER -p -e "DROP DATABASE IF EXISTS $DB_NAME;"

echo "Creating database..."
mysql -u $DB_USER -p -e "CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "Running migrations..."
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php

echo "Done!"
```

Save as `database/clean-db.sh` and make it executable:
```bash
chmod +x database/clean-db.sh
```

## After Migration

After running migrations, you should have:

- ✅ All core tables created
- ✅ All provider tables created
- ✅ All indexes created
- ✅ All foreign keys established
- ✅ Migration tracking table (`migrations`) populated

You can now use the application normally!

