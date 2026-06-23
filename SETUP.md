# Setup Guide

## Quick Start

### 1. Database Setup

```bash
# Create database (if it doesn't exist)
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS wws_shopify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations to create all tables
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php
```

**Note**: The project now uses database migrations instead of SQL files. See `database/MIGRATION_README.md` for details.

### 2. Environment Configuration

```bash
# Copy example environment file
cp .env.example .env

# Edit .env with your credentials
nano .env  # or use your preferred editor
```

**Required Configuration:**

```env
# Database
DB_HOST=localhost
DB_NAME=wws_shopify
DB_USER=your_db_user
DB_PASSWORD=your_db_password

# WwsRestService API
WWS_BASE_URL=https://46.235.144.254:64003/datasnap/rest/TWwsServerMethods
WWS_DATABASE_ID=1
WWS_USERNAME=your_wws_username
WWS_PASSWORD=your_wws_password

# Shopify
SHOPIFY_SHOP_DOMAIN=your-shop.myshopify.com
SHOPIFY_ACCESS_TOKEN=your_shopify_access_token
SHOPIFY_WEBHOOK_SECRET=your_webhook_secret
```

### 3. Test Connections

Visit in browser or use curl:
```bash
curl http://localhost/api/test-connection.php
```

Expected response:
```json
{
  "success": true,
  "results": {
    "database": true,
    "wws_api": true,
    "shopify_api": true
  }
}
```

### 4. Initial Product Sync

**Via Web (Testing):**
```bash
curl "http://localhost/api/sync-products.php?limit=10"
```

**Via CLI:**
```bash
php cli/sync-products.php --limit=10
```

### 5. Shopify Webhook Setup

1. Go to Shopify Admin → Settings → Notifications → Webhooks
2. Click "Create webhook"
3. Configure:
   - **Event**: Order creation
   - **Format**: JSON
   - **URL**: `https://your-domain.com/api/webhook/shopify-order.php`
   - **API version**: Latest
4. Copy the webhook secret and add to `.env`:
   ```env
   SHOPIFY_WEBHOOK_SECRET=your_webhook_secret_here
   ```

### 6. Process Orders

Orders from webhooks are automatically queued. Process them manually:

```bash
php cli/process-pending-orders.php --limit=10
```

## Directory Structure

```
wws/
├── config/              # Configuration files
│   ├── config.php       # Main config (loads .env)
│   └── field-mapping.php # Product field mapping
├── src/                  # Source code
│   ├── Api/             # API clients
│   ├── Services/        # Business logic
│   ├── Database/        # Database layer
│   └── Utils/           # Utilities
├── public/              # Web entry point
│   └── api/            # API endpoints
├── cli/                 # CLI commands
└── database/            # Database schema
```

## Common Tasks

### Sync Single Product
```bash
# By ID
php cli/sync-product.php --id=123

# By SKU
php cli/sync-product.php --sku=ABC123
```

### View Logs
```bash
# Sync logs
curl "http://localhost/api/logs/sync-logs.php?limit=50&status=failed"

# API logs
curl "http://localhost/api/logs/api-logs.php?limit=50&api_type=wws"
```

### Retry Failed Operations
```bash
php cli/retry-failed-syncs.php --type=product_sync --limit=10
```

## Troubleshooting

### Database Connection Error
- Check `.env` database credentials
- Verify database exists: `mysql -u root -p -e "SHOW DATABASES;"`
- Check user permissions

### API Connection Errors
- Verify credentials in `.env`
- Check network connectivity
- For WwsRestService: Verify SSL certificate settings (`WWS_VERIFY_SSL=false` for self-signed)

### Webhook Not Working
- Verify webhook URL is accessible
- Check `SHOPIFY_WEBHOOK_SECRET` matches Shopify webhook secret
- Check webhook logs in Shopify admin
- Verify `WEB_ENABLED=true` in config

### Products Not Syncing
- Check `sync_logs` table for errors
- Verify field mapping in `config/field-mapping.php`
- Test API connection: `/api/test-connection.php`
- Check API logs: `/api/logs/api-logs.php`

## Production Deployment

1. **Disable Web Endpoints** (if using CLI only):
   ```env
   WEB_ENABLED=false
   ```

2. **Set Production Environment**:
   ```env
   APP_ENV=production
   ```

3. **Set up Cron Jobs** (optional):
   ```cron
   # Sync products daily at 2 AM
   0 2 * * * cd /path/to/wws && php cli/sync-products.php

   # Process pending orders every 5 minutes
   */5 * * * * cd /path/to/wws && php cli/process-pending-orders.php --limit=50
   ```

4. **Monitor Logs**:
   - Check `sync_logs` table regularly
   - Monitor `api_logs` for API issues
   - Set up alerts for failed syncs

## Security Notes

- Never commit `.env` file to version control
- Use strong webhook secrets
- Restrict web endpoint access in production (use authentication)
- Keep API credentials secure
- Regularly rotate access tokens

