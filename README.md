# Curion — WwsRestService ↔ Shopify Integration Platform

A PHP-based OOP middleware that synchronises products and inventory from the **WwsRestService ERP** (Delphi DataSnap) to **Shopify**, and processes Shopify orders back to the ERP as transactions.

---

## Features

- **Product Sync** — Fetch products from WWS `productSearch` → map fields → create/update Shopify products
- **Inventory Sync** — Keep Shopify stock levels in sync with WWS `quantityAvailable1` (DE Transco site)
- **Bundle Support** — Detects WWS bundle products (`stockManagement.id` 101/102) and handles them via the Shopify Bundles app; inventory is managed by Shopify, not pushed from WWS
- **Order Processing** — Receive Shopify order webhooks → search/create WWS customer → create WWS transaction
- **Customer Deduplication** — Searches WWS by email before creating a new address record; reuses existing records to prevent duplicates
- **Galaxus Order Handling** — Orders tagged `Galaxus` use a fixed billing address (ID 9983), `faktArt` 142, and `termsOfPayment` 341; the actual shipping address is still passed
- **Price Sync** — Syncs `passantPrice` (retail) and `basePrice` (compare-at) from WWS to Shopify
- **UpPromote Affiliate Integration** — Receives UpPromote referral webhooks and links commissions to WWS transactions
- **Scheduler** — Runs recurring sync tasks via cron
- **Error Queue** — Failed operations are persisted to the database and can be retried with exponential backoff
- **Multi-Provider Architecture** — Abstract ERP and e-commerce provider contracts; designed to support additional ERPs/platforms without refactoring core logic
- **Web & CLI Support** — All sync operations available as both HTTP endpoints (for testing) and CLI commands (for production cron)
- **Comprehensive Logging** — Multi-level logs written to flat files and the database (`sync_logs`, `api_logs`)

---

## Installation

### 1. Clone the repository

```bash
git clone git@github.com:sanju40/greenegg-curion.git
cd greenegg-curion
```

### 2. Install dependencies

```bash
composer install
```

### 3. Configure environment

```bash
cp .env.example .env
# Edit .env with your credentials (see Configuration section below)
```

### 4. Run database migrations

```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php
```

---

## Configuration

### Environment Variables (`.env`)

```env
# ── Application ───────────────────────────────────────────────
WEB_ENABLED=true

# ── Database ──────────────────────────────────────────────────
DB_HOST=localhost
DB_NAME=wws_shopify
DB_USER=root
DB_PASSWORD=

# ── WWS ERP API ───────────────────────────────────────────────
WWS_BASE_URL=https://wwsmebo.cloud.onax.ch/datasnap/rest/TWwsServerMethods
WWS_USERNAME=your_username
WWS_PASSWORD=your_password

# Separate endpoint used only for order/transaction calls (can be the same URL)
WWS_ORDERS_BASE_URL=https://wwsmebo.cloud.onax.ch/datasnap/rest/TWwsServerMethods
WWS_ORDERS_USERNAME=your_username
WWS_ORDERS_PASSWORD=your_password

WWS_TIMEOUT=30           # Total request timeout (seconds)
WWS_CONNECT_TIMEOUT=15   # Connection timeout (seconds)

# ── Shopify ───────────────────────────────────────────────────
SHOPIFY_SHOP_DOMAIN=your-shop.myshopify.com
SHOPIFY_API_KEY=your_api_key
SHOPIFY_API_SECRET=your_api_secret
SHOPIFY_ACCESS_TOKEN=your_access_token
SHOPIFY_WEBHOOK_SECRET=your_webhook_secret

# ── Logging ───────────────────────────────────────────────────
LOG_ENABLED=true
LOG_LEVEL=info           # debug | info | warning | error | critical

# ── Order processing ──────────────────────────────────────────
ORDER_MAX_RETRIES=3
ORDER_RETRY_DELAY_BASE=5
ORDER_DRY_RUN=false      # Set true to simulate processing without writing to WWS
PRICES_WITHOUT_VAT=true
SHIPPING_PRICES_WITHOUT_VAT=true

# ── Bundle products ───────────────────────────────────────────
BUNDLES_ENABLED=true
# WWS stockManagement IDs that indicate a bundle product
# 101 = variable bundle, 102 = fixed bundle
BUNDLE_STOCK_MANAGEMENT_IDS=101,102

# ── UpPromote affiliate integration ───────────────────────────
UPPROMOTE_ENABLED=true
UPPROMOTE_API_KEY=your_uppromote_api_key
UPPROMOTE_WEBHOOK_SECRET=your_uppromote_webhook_secret

# ── Auto-deploy (GitHub webhook) ──────────────────────────────
GIT_WEBHOOK_SECRET=your_generated_secret   # see DEPLOY.md
GIT_DEBUG=false                            # set true to log every deploy step
```

### Config Files (`config/`)

| File | Purpose |
|---|---|
| `config.php` | Global app config (timeouts, batch sizes, feature flags) |
| `field-mapping.php` | Maps WWS product fields to Shopify fields |
| `order-routing.php` | Rules for routing orders to WWS transaction types |
| `customer-matching.php` | Customer deduplication matching rules |
| `conflict-rules.php` | Conflict resolution rules for inventory/price disagreements |
| `scheduler.php` | Scheduled task definitions |

---

## Order Processing Flow

When a Shopify order webhook fires, the following steps occur:

1. **Webhook received** — HMAC signature is validated; order JSON is stored in the `order_queue` table
2. **Customer lookup** — `customerSearch` is called on the WWS API using the order email; if a matching record is found it is reused
3. **Customer creation** — Only if no match is found, a new WWS address record is created via `customer/new`
4. **Galaxus detection** — If the Shopify order has a `Galaxus` tag, the billing address is overridden to ID 9983, `faktArt` → 142, `termsOfPayment` → 341
5. **Transaction creation** — A `transaction/new` POST is sent to WWS including the resolved customer ID, order lines, payment method, and explicit `shippingAddress`

---

## CLI Commands

All commands require `CLI_ENABLED=true` in config. Run from the project root.

### Product Sync

```bash
# Sync all products from WWS to Shopify
php cli/sync-products.php

# Sync a single product by WWS product ID
php cli/sync-product.php --id=123

# Sync a single product by SKU
php cli/sync-product.php --sku=ABC123

# Update bundle prices only
php cli/update-bundle-prices.php
```

### Customer Sync

```bash
# Sync all customers from WWS to Shopify
php cli/sync-customers.php

# Sync a single customer by WWS customer ID
php cli/sync-customer.php --id=456
```

### Order Processing

```bash
# Process all orders sitting in the queue
php cli/process-pending-orders.php

# Re-sync orders that are missing from WWS
php cli/resync-missing-orders.php

# Process the error queue (failed operations with retry backoff)
php cli/process-error-queue.php
```

### Debug / Dry-Run

```bash
# Preview the WWS transaction payload for a Shopify order (no writes)
php cli/preview-order-payload.php --order-id=13161915285878

# Retry failed sync operations
php cli/retry-failed-syncs.php --type=product_sync --limit=10
```

### Database

```bash
# Run all pending migrations
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php

# Roll back the last migration batch
php cli/rollback.php

# Create a new migration file
php cli/create-migration.php --name=add_new_column

# Clean old log data from the database
php cli/clean-database.php

# Import existing Shopify products into product_mappings table
php cli/import-shopify-mappings.php
```

### Scheduler & Affiliates

```bash
# Run the scheduler (intended for cron, e.g. every minute)
php cli/run-scheduler.php

# Link pending UpPromote affiliate referrals to WWS transactions
php cli/link-pending-affiliate-referrals.php
```

---

## Web Endpoints

All endpoints require `WEB_ENABLED=true` in `.env`. The entry point is `public/index.php`.

### Health

| Method | Path | Description |
|---|---|---|
| GET | `/api/health` | Returns `{"status":"ok"}` — useful for uptime monitoring |

### Product Sync

| Method | Path | Description |
|---|---|---|
| GET | `/api/sync-products` | Sync all products. Params: `limit`, `offset`, `page`, `bundles_only=1` |
| GET | `/api/sync-single-product` | Sync one product. Params: `id` or `sku` |
| GET | `/api/import-shopify-mappings` | Import existing Shopify products into `product_mappings` |

### Customer Sync

| Method | Path | Description |
|---|---|---|
| GET | `/api/sync-customers` | Sync all WWS customers. Params: `provider`, `limit` |
| GET | `/api/sync-single-customer` | Sync one customer. Params: `id` or `email` |

### Logs

| Method | Path | Description |
|---|---|---|
| GET | `/api/logs/sync-logs` | View sync logs. Params: `limit`, `status`, `operation_type` |
| GET | `/api/logs/api-logs` | View API logs. Params: `limit`, `api_type` |

### Testing

| Method | Path | Description |
|---|---|---|
| GET | `/api/test-connection` | Test DB, WWS API, and Shopify API connectivity |
| GET | `/api/test-affiliate-sync` | Manually trigger affiliate linking. Params: `shopify_order_id`, `affiliate_id` |

### Webhooks

| Method | Path | Description |
|---|---|---|
| POST | `/api/webhook/shopify-order` | Shopify `orders/create` webhook (HMAC validated) |
| POST | `/api/webhook/shopify-product` | Shopify `products/create|update|delete` webhook |
| GET/POST | `/api/webhook/uppromote-referral` | UpPromote referral webhook |

---

## Auto-Deploy (GitHub → Server)

Every push to `main` automatically deploys to the production server via:

| File | Purpose |
|---|---|
| `public/git-webhook.php` | Receives GitHub push webhook, validates HMAC signature, triggers deploy |
| `git-deploy.sh` | Runs `git pull`, `composer install`, and fixes permissions |

**Webhook URL:** `https://curion.techsystintel.com/git-webhook.php`

For full setup instructions (first-time server configuration, SSH deploy keys, GitHub webhook registration) see **[DEPLOY.md](DEPLOY.md)**.

---

## Webhook Setup

### Shopify

1. In Shopify admin → **Settings → Notifications → Webhooks**
2. Add the following webhooks:

| Event | URL |
|---|---|
| Orders / Order creation | `https://your-domain.com/api/webhook/shopify-order` |
| Products / Product creation | `https://your-domain.com/api/webhook/shopify-product` |
| Products / Product update | `https://your-domain.com/api/webhook/shopify-product` |
| Products / Product deletion | `https://your-domain.com/api/webhook/shopify-product` |

3. Set **Format** to JSON and copy the webhook secret into `SHOPIFY_WEBHOOK_SECRET` in `.env`

### UpPromote

Configure the referral webhook URL in your UpPromote dashboard:
`https://your-domain.com/api/webhook/uppromote-referral`

---

## Database Schema

Run migrations with:

```bash
php -dmemory_limit=-1 -dmax_execution_time=-1 cli/migrate.php
```

| Table | Purpose |
|---|---|
| `sync_logs` | Tracks all synchronisation operations (product, inventory, customer) |
| `api_logs` | Logs every outbound API call with request/response data |
| `product_mappings` | Maps WWS product IDs/SKUs to Shopify product/variant IDs |
| `order_queue` | Holds incoming Shopify orders awaiting WWS transaction creation |
| `customer_mappings` | Maps Shopify customer IDs to WWS address IDs |
| `providers` | Registry of configured ERP and e-commerce providers |
| `customer_sync_progress` | Pagination cursor for large customer sync runs |
| `affiliate_referrals` | UpPromote referral records pending linkage to WWS transactions |
| `error_queue` | Failed operations with retry count and backoff state |

See `database/MIGRATION_README.md` for full migration documentation.

---

## Project Structure

```
public_html/
├── cli/                        # CLI scripts (run via cron or manually)
├── config/                     # Configuration files
│   ├── config.php
│   ├── field-mapping.php
│   ├── order-routing.php
│   ├── customer-matching.php
│   ├── conflict-rules.php
│   └── scheduler.php
├── database/                   # Migrations and migration docs
│   └── migrations/
├── git-deploy.sh               # Auto-deploy script (triggered by git-webhook.php)
├── git-logs/                   # Deploy logs — created automatically, gitignored
├── logs/                       # App flat-file logs (gitignored except .gitkeep)
├── public/                     # Document root (served by nginx)
│   ├── index.php               # App entry point — routes all /api/* requests
│   └── git-webhook.php         # GitHub auto-deploy webhook (served directly by nginx)
├── src/
│   ├── Adapters/               # Maps between core Product model and provider formats
│   │   ├── Shopify/
│   │   └── WwsRestService/
│   ├── Api/                    # Raw HTTP API clients
│   │   ├── Shopify/
│   │   ├── UpPromote/
│   │   └── WwsRestService/
│   ├── Core/
│   │   ├── Conflict/           # Conflict resolution for inventory/price
│   │   ├── Contracts/          # ErpProviderInterface, EcommerceProviderInterface
│   │   ├── ErrorQueue/         # Error queue processor and retry logic
│   │   ├── Factory/            # ProviderFactory
│   │   ├── Models/             # Core domain models (Product, etc.)
│   │   ├── Registry/           # Provider registry
│   │   ├── Routing/            # Laravel-style router + route definitions
│   │   ├── Scheduler/          # Scheduled task runner
│   │   └── Services/           # Business logic services
│   ├── Database/               # DB connection, migrations, repositories
│   ├── Exceptions/             # Custom exception classes
│   ├── Providers/              # Concrete ERP and e-commerce provider implementations
│   │   ├── Ecommerce/Shopify/
│   │   └── Erp/WwsRestService/
│   └── Utils/                  # Logger, LogHelper, helpers
└── vendor/                     # Composer dependencies (gitignored)
```

---

## Error Handling

- All API calls are logged to `api_logs` with full request/response data
- All sync operations are logged to `sync_logs`
- Failed order processing is retried up to `ORDER_MAX_RETRIES` times with exponential backoff (`ORDER_RETRY_DELAY_BASE` seconds base)
- The error queue (`cli/process-error-queue.php`) handles retries for non-order failures
- Set `ORDER_DRY_RUN=true` to simulate order processing without writing to WWS

---

## Security

- Shopify webhook payloads validated via HMAC-SHA256 (`SHOPIFY_WEBHOOK_SECRET`)
- UpPromote webhook payloads validated via HMAC-SHA256 (`UPPROMOTE_WEBHOOK_SECRET`)
- GitHub auto-deploy webhook validated via HMAC-SHA256 (`GIT_WEBHOOK_SECRET`)
- All credentials stored in `.env` — never committed to the repository
- Prepared statements used for all database queries

---

## Troubleshooting

| Problem | Solution |
|---|---|
| API connection fails | Run `GET /api/test-connection` to test DB, WWS, and Shopify individually |
| Orders not processing | Check `order_queue` table status and `sync_logs` for errors |
| Duplicate WWS customers | Verify `customerSearch` returns the correct email match in `api_logs` |
| Product not syncing | Run `php cli/sync-product.php --sku=ABC123` and check output |
| Wrong stock levels | Confirm WWS `productSearch` response includes `stock.quantityAvailable1` |
| Webhook not firing | Check `SHOPIFY_WEBHOOK_SECRET` matches the secret configured in Shopify admin |
| Auto-deploy not triggering | Check `git-logs/deploy.log` on the server; see `DEPLOY.md` for troubleshooting |
| Auto-deploy returns 403 | `GIT_WEBHOOK_SECRET` in `.env` does not match the secret set in GitHub |

---

## License

Proprietary — Big Green Egg / Curion Integration
