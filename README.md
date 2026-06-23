# WwsRestService to Shopify Integration

A PHP-based OOP application that synchronizes products from WwsRestService API to Shopify and processes Shopify orders back to the API.

## Features

- **Product Synchronization**: Fetch products from WwsRestService API → Map fields → Sync to Shopify
- **Order Processing**: Receive Shopify webhooks → Create transactions in WwsRestService API
- **Database Logging**: Track all operations for debugging and retry capability
- **Web & CLI Support**: Web endpoints for testing, CLI for production
- **Configuration Management**: Enable/disable features via config

## Installation

1. **Clone or navigate to the project directory**
   ```bash
   cd wws
   ```

2. **Set up environment variables**
   ```bash
   cp .env.example .env
   # Edit .env with your credentials
   ```

3. **Set up database**
   ```bash
   # Run migrations to create database schema
   php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php
   ```

4. **Configure your .env file**
   - Add WwsRestService API credentials
   - Add Shopify API credentials
   - Configure database connection

## Configuration

### Environment Variables (.env)

```env
# Application
APP_ENV=development
APP_TIMEZONE=UTC

# Database
DB_HOST=localhost
DB_NAME=wws_shopify
DB_USER=root
DB_PASSWORD=

# WwsRestService API
WWS_BASE_URL=https://46.235.144.254:64003/datasnap/rest/TWwsServerMethods
WWS_DATABASE_ID=1
WWS_USERNAME=your_username
WWS_PASSWORD=your_password
WWS_VERIFY_SSL=false

# Shopify
SHOPIFY_SHOP_DOMAIN=your-shop.myshopify.com
SHOPIFY_API_KEY=your_api_key
SHOPIFY_API_SECRET=your_api_secret
SHOPIFY_ACCESS_TOKEN=your_access_token
SHOPIFY_API_VERSION=2024-01
SHOPIFY_WEBHOOK_SECRET=your_webhook_secret

# Features
WEB_ENABLED=true
CLI_ENABLED=true
SYNC_BATCH_SIZE=50
SYNC_MAX_RETRIES=3
SYNC_RETRY_DELAY=60
```

### Field Mapping

Edit `config/field-mapping.php` to customize how WwsRestService product fields map to Shopify format.

## Usage

### Web Endpoints (Testing)

All endpoints require `WEB_ENABLED=true` in config.

#### Sync Products
- `GET /api/sync-products.php?limit=100` - Sync all products
- `GET /api/sync-single-product.php?id=123` - Sync single product by ID
- `GET /api/sync-single-product.php?sku=ABC123` - Sync single product by SKU

#### Testing
- `GET /api/test-connection.php` - Test all API connections

#### Logs
- `GET /api/logs/sync-logs.php?limit=100&status=failed` - View sync logs
- `GET /api/logs/api-logs.php?limit=100&api_type=wws` - View API logs

#### Webhooks
- `POST /api/webhook/shopify-order.php` - Shopify order webhook endpoint

### CLI Commands

All commands require `CLI_ENABLED=true` in config.

#### Product Sync
```bash
# Sync all products
php cli/sync-products.php --limit=100

# Sync single product by ID
php cli/sync-product.php --id=123

# Sync single product by SKU
php cli/sync-product.php --sku=ABC123
```

#### Order Processing
```bash
# Process pending orders
php cli/process-pending-orders.php --limit=10
```

#### Retry Failed Operations
```bash
# Retry failed syncs
php cli/retry-failed-syncs.php --type=product_sync --limit=10
```

## Webhook Setup

1. In your Shopify admin, go to Settings → Notifications → Webhooks
2. Create a new webhook:
   - Event: Order creation
   - Format: JSON
   - URL: `https://your-domain.com/api/webhook/shopify-order.php`
   - Secret: Set `SHOPIFY_WEBHOOK_SECRET` in your .env file

## Database Schema

The application uses database migrations to manage schema. Run migrations with:
```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php
```

Main tables:
- `sync_logs` - Tracks all synchronization operations
- `api_logs` - Logs all API calls
- `product_mappings` - Maps WwsRestService products to Shopify products
- `order_queue` - Queue for processing orders
- `customer_mappings` - Maps Shopify customers to WwsRestService customers
- `providers` - Provider registry for multi-ERP support
- `customer_sync_progress` - Tracks customer sync pagination

See `database/MIGRATION_README.md` for migration documentation.

## Project Structure

```
wws/
├── config/              # Configuration files
├── src/                 # Source code
│   ├── Api/            # API clients
│   ├── Services/       # Business logic
│   ├── Database/       # Database layer
│   ├── Exceptions/     # Custom exceptions
│   └── Utils/          # Utilities
├── public/             # Web entry point
│   └── api/           # API endpoints
├── cli/                # CLI commands
└── database/           # Database schema
```

## Error Handling

- All API calls are logged to `api_logs` table
- All sync operations are logged to `sync_logs` table
- Failed operations can be retried using CLI commands
- Errors are stored with full request/response data for debugging

## Security

- Webhook validation using HMAC signature
- Credentials stored in `.env` file (never commit to repository)
- Input validation on all endpoints
- Prepared statements for database queries

## Troubleshooting

1. **Check API connections**: Use `/api/test-connection.php`
2. **View logs**: Check `sync_logs` and `api_logs` tables
3. **Retry failed operations**: Use CLI retry commands
4. **Check configuration**: Verify `.env` file settings

## License

Proprietary - Big Green Egg Integration

