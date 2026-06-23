# Database Migration System Guide

## Overview

The migration system has been integrated from the `ofyr.swiss-master` project and adapted to work with this codebase. It provides a clean, version-controlled way to manage database schema changes.

## Quick Start

### 1. Create a New Migration

```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/create-migration.php --model=create_customers_table
```

This creates a file like: `database/migrations/20250115_120000_create_customers_table.php`

### 2. Edit the Migration File

Open the created file and add your SQL:

```php
<?php
return [
    'up' => "
        CREATE TABLE IF NOT EXISTS example_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ",
    'down' => "
        DROP TABLE IF EXISTS example_table
    "
];
```

### 3. Run Migrations

```bash
# Run all pending migrations
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php

# Run a specific migration
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php --path=migrations/20250115_120000_create_customers_table.php
```

### 4. Rollback Migrations

```bash
# Rollback last batch (default: 1 batch)
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/rollback.php

# Rollback multiple batches
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/rollback.php --step=2

# Rollback specific migration
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/rollback.php --path=migrations/20250115_120000_create_customers_table.php
```

## Migration File Structure

Each migration file must return an array with two keys:

- **`up`**: SQL to apply the migration (CREATE, ALTER, INSERT, etc.)
- **`down`**: SQL to rollback the migration (DROP, ALTER, DELETE, etc.)

### Example: Creating a Table

```php
<?php
return [
    'up' => "
        CREATE TABLE IF NOT EXISTS my_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
    'down' => "
        DROP TABLE IF EXISTS my_table;
    "
];
```

### Example: Adding a Column

```php
<?php
return [
    'up' => "
        ALTER TABLE my_table 
        ADD COLUMN email VARCHAR(255) NULL AFTER name;
    ",
    'down' => "
        ALTER TABLE my_table 
        DROP COLUMN email;
    "
];
```

## Migration Tracking

The system automatically creates a `migrations` table that tracks:
- Which migrations have been executed
- Batch numbers (migrations run together get the same batch)
- Execution timestamps

### Check Migration Status

```sql
SELECT * FROM migrations ORDER BY batch DESC, executed_at DESC;
```

## Best Practices

1. **Always include rollback SQL**: Every `up` migration must have a corresponding `down` migration
2. **Use IF NOT EXISTS / IF EXISTS**: Makes migrations idempotent and safe to re-run
3. **Test rollbacks**: Always verify your `down` migration works
4. **One change per migration**: Keep migrations focused on a single logical change
5. **Use transactions where possible**: Wrap related changes
6. **Document complex migrations**: Add comments for complex SQL
7. **Never modify existing migrations**: Create a new migration instead

## Important Notes

### MySQL Limitations

MySQL doesn't support `IF NOT EXISTS` or `IF EXISTS` in `ALTER TABLE` statements. For adding columns, you have two options:

1. **Check manually before running** (recommended for production):
   ```php
   'up' => "
       -- Check if column exists first (manual check required)
       ALTER TABLE my_table ADD COLUMN new_field VARCHAR(255) NULL;
   "
   ```

2. **Use a stored procedure** (for complex checks)

3. **Handle errors gracefully**: The migration system will continue with other migrations if one fails

### Migration Order

Migrations are executed in alphabetical order based on their filename. The timestamp prefix ensures chronological ordering:
- `20250101_000000_*` runs before `20250101_000001_*`
- `20250101_*` runs before `20250102_*`

## Existing Migrations

The following migrations are included:

1. **20250101_000000_create_initial_schema.php**
   - Creates: `sync_logs`, `api_logs`, `product_mappings`, `order_queue`, `customer_mappings`

2. **20250101_000001_create_customer_sync_progress_table.php**
   - Creates: `customer_sync_progress` (for pagination tracking)

3. **20250101_000002_create_providers_schema.php**
   - Creates: `providers`, `provider_capabilities`, `job_queue`, `error_queue`, `audit_logs`
   - Enhances existing tables with provider-related columns

## Troubleshooting

### Migration fails with "Column already exists"

This happens when trying to add a column that already exists. Options:
1. Check if the column exists before adding it (manual check)
2. Create a new migration to handle the column addition conditionally
3. Rollback and fix the migration file

### Need to reset all migrations

⚠️ **WARNING**: This deletes all migration history!

```sql
DROP TABLE IF EXISTS migrations;
```

Then run `cli/migrate.php` again to re-run all migrations.

### Migration file has syntax error

Fix the PHP syntax error in the migration file and run the migration again. The system will skip migrations that have already been executed.

### Database connection fails

Ensure your `.env` file has correct database credentials:
```env
DB_HOST=localhost
DB_NAME=wws_shopify
DB_USER=root
DB_PASSWORD=your_password
```

## Migration vs. Manual SQL Files

**Before**: Manual SQL files executed with `mysql -u root -p wws_shopify < database/schema.sql`

**Now**: Use migrations for all schema changes:
- Version controlled
- Reversible (rollback support)
- Tracked in database
- Can run specific migrations
- Safe to re-run

The old SQL files (`schema.sql`, `schema_providers.sql`, etc.) are kept for reference but should not be used directly. All new changes should be done via migrations.

