# Database Migration System

This project uses a migration system to manage database schema changes in a version-controlled, reversible way.

## Overview

Migrations are stored in `database/migrations/` and are executed in chronological order based on their timestamp prefix. Each migration file contains:
- `up`: SQL to apply the migration
- `down`: SQL to rollback the migration

## Commands

### Create a New Migration

```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/create-migration.php --model=create_customers_table
```

This creates a new migration file with a timestamp prefix:
```
database/migrations/20250101_120000_create_customers_table.php
```

### Run Migrations

Run all pending migrations:
```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php
```

Run a specific migration file:
```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php --path=migrations/20250101_120000_create_customers_table.php
```

### Rollback Migrations

Rollback the last batch (default: 1 batch):
```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/rollback.php
```

Rollback multiple batches:
```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/rollback.php --step=2
```

Rollback a specific migration:
```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/rollback.php --path=migrations/20250101_120000_create_customers_table.php
```

## Migration File Format

Each migration file should return an array with `up` and `down` keys:

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

## Migration Tracking

The system automatically creates a `migrations` table to track which migrations have been executed. This prevents:
- Running the same migration twice
- Missing migrations when running in different environments
- Out-of-order execution

## Best Practices

1. **Always include rollback SQL**: Every `up` migration should have a corresponding `down` migration
2. **Use IF NOT EXISTS / IF EXISTS**: Makes migrations idempotent
3. **Test rollbacks**: Always test that your `down` migration works correctly
4. **One change per migration**: Keep migrations focused on a single change
5. **Use transactions where possible**: Wrap related changes in transactions
6. **Document complex migrations**: Add comments explaining complex SQL logic

## Existing Migrations

The following migrations are included:

1. **20250101_000000_create_initial_schema.php**: Creates core tables (sync_logs, api_logs, product_mappings, order_queue, customer_mappings)
2. **20250101_000001_create_customer_sync_progress_table.php**: Creates customer sync progress tracking table
3. **20250101_000002_create_providers_schema.php**: Creates provider-related tables and adds foreign keys

## Migration Status

Check which migrations have been run:
```sql
SELECT * FROM migrations ORDER BY batch DESC, executed_at DESC;
```

## Troubleshooting

### Migration fails mid-execution
If a migration fails, you may need to manually fix the database state before retrying. Check the error message and fix any issues, then run the migration again.

### Need to reset all migrations
To start fresh (⚠️ **WARNING**: This will delete all migration history):
```sql
DROP TABLE IF EXISTS migrations;
```
Then run `cli/migrate.php` again to re-run all migrations.

### Migration file syntax error
If a migration file has a PHP syntax error, fix it and run the migration again. The system will skip migrations that have already been executed.

