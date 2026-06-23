# Customer Synchronization Guide

## Overview

The customer synchronization system provides **bidirectional sync** between ERP providers (WWS) and Shopify. Customers can be synced from ERP to Shopify (inbound) or from Shopify to ERP (outbound).

---

## Features

### ✅ Implemented Features

1. **Bidirectional Sync**
   - ✅ ERP → Shopify (Inbound)
   - ✅ Shopify → ERP (Outbound)

2. **Customer Matching**
   - ✅ Email-based matching (primary)
   - ✅ Customer number matching (secondary)
   - ✅ Phone number matching (tertiary)

3. **Automatic Operations**
   - ✅ Create customers if they don't exist
   - ✅ Update customers if they exist
   - ✅ Maintain customer mappings

4. **Integration Points**
   - ✅ CLI commands for manual sync
   - ✅ Web endpoints for testing
   - ✅ Scheduler integration (automatic sync)
   - ✅ Error queue retry support
   - ✅ Order processing integration (auto-create customers)

---

## Usage

### CLI Commands

#### Sync All Customers (ERP → Shopify)
```bash
# Sync all customers from WWS to Shopify
php cli/sync-customers.php

# Sync with limit
php cli/sync-customers.php --limit=50

# Sync from specific provider
php cli/sync-customers.php --provider=wws --limit=100
```

#### Sync Single Customer (ERP → Shopify)
```bash
# Sync by customer ID
php cli/sync-customer.php --id=123 --provider=wws

# Sync by email
php cli/sync-customer.php --email=user@example.com --provider=wws
```

### Web Endpoints

#### Sync All Customers
```
GET /api/sync-customers?provider=wws&limit=100
```

**Response:**
```json
{
  "success": true,
  "result": {
    "synced": 45,
    "errors": 2
  }
}
```

#### Sync Single Customer
```
GET /api/sync-single-customer?id=123&provider=wws
GET /api/sync-single-customer?email=user@example.com&provider=wws
```

**Response:**
```json
{
  "success": true,
  "result": {
    "id": "789123456",
    "email": "user@example.com",
    "first_name": "John",
    "last_name": "Doe",
    ...
  }
}
```

---

## Automatic Sync

### Scheduled Sync

Customer sync runs automatically via the scheduler. Configure in `config/scheduler.php`:

```php
'customer_sync' => [
    'enabled' => env('CUSTOMER_SYNC_ENABLED', 'true') === 'true',
    'interval' => env('CUSTOMER_SYNC_INTERVAL', '1h'), // Every hour
    'provider' => env('CUSTOMER_SYNC_PROVIDER', 'wws'),
],
```

**Environment Variables:**
```env
CUSTOMER_SYNC_ENABLED=true
CUSTOMER_SYNC_INTERVAL=1h
CUSTOMER_SYNC_PROVIDER=wws
```

### Order Processing Integration

When an order is placed in Shopify, the system automatically:
1. Checks if customer exists in ERP
2. Creates customer in ERP if not found
3. Updates customer in ERP if found
4. Maintains customer mapping

This happens automatically in `OrderProcessingService::getOrCreateCustomerInErp()`.

---

## Customer Matching Rules

Configure matching rules in `config/customer-matching.php`:

```php
return [
    'matching_strategy' => [
        'primary' => 'email',           // Primary match field
        'secondary' => 'customer_number', // Fallback
        'tertiary' => 'phone',          // Last resort
    ],
    
    'sync_direction' => [
        'inbound' => true,   // Provider → Shopify
        'outbound' => true,  // Shopify → Provider
    ],
    
    'outbound_triggers' => [
        'customer_created' => true,
        'customer_updated' => true,
        'order_placed' => true, // Create customer if doesn't exist
    ],
];
```

---

## Data Flow

### Inbound Sync (ERP → Shopify)

1. **Fetch customers from ERP**
   ```php
   $customers = $erpProvider->searchCustomers('*', 0, $limit);
   ```

2. **For each customer:**
   - Convert ERP format → Core Model (via `WwsCustomerAdapter`)
   - Check if exists in Shopify (by email)
   - If exists: Update in Shopify
   - If not exists: Create in Shopify
   - Save customer mapping

3. **Log results**
   - Success/failure logged to `sync_logs`
   - Customer mappings saved to `customer_mappings`

### Outbound Sync (Shopify → ERP)

1. **Receive Shopify customer data**
   - From webhook, order processing, or manual sync

2. **Check if exists in ERP**
   - Search by email in ERP

3. **If exists:**
   - Convert Shopify format → Core Model → ERP format
   - Update customer in ERP
   - Update mapping

4. **If not exists:**
   - Convert Shopify format → Core Model → ERP format
   - Create customer in ERP
   - Create mapping

---

## Customer Mapping

Customer mappings are stored in `customer_mappings` table:

```sql
CREATE TABLE customer_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shopify_customer_id VARCHAR(255) NOT NULL,
    wws_customer_id VARCHAR(255) NULL,
    customer_data TEXT NULL, -- JSON
    last_synced_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

**Repository:** `CustomerMappingRepository`
- `findByShopifyCustomerId($id)`
- `findByWwsCustomerId($id)`
- `save($shopifyId, $wwsId, $customerData)`

---

## Error Handling

### Error Queue

Failed customer syncs are automatically added to the error queue:

```php
// Process error queue
php cli/process-error-queue.php --limit=50
```

**Retry Logic:**
- Exponential backoff (5, 10, 20 minutes)
- Max retries: 3 (configurable)
- Dead-letter queue for permanent failures

### Logging

All customer sync operations are logged:

**Sync Logs:**
```sql
SELECT * FROM sync_logs 
WHERE operation_type = 'customer_sync' 
ORDER BY created_at DESC;
```

**API Logs:**
```sql
SELECT * FROM api_logs 
WHERE api_type IN ('wws', 'shopify')
AND endpoint LIKE '%customer%'
ORDER BY created_at DESC;
```

---

## Testing

### Test Customer Sync

1. **Test single customer:**
   ```bash
   php cli/sync-customer.php --email=test@example.com --provider=wws
   ```

2. **Test batch sync:**
   ```bash
   php cli/sync-customers.php --limit=10 --provider=wws
   ```

3. **Check logs:**
   ```bash
   # View sync logs
   mysql -u root -p -e "SELECT * FROM sync_logs WHERE operation_type='customer_sync' ORDER BY created_at DESC LIMIT 10;"
   
   # View customer mappings
   mysql -u root -p -e "SELECT * FROM customer_mappings ORDER BY created_at DESC LIMIT 10;"
   ```

### Web Testing

```bash
# Test sync all customers
curl "http://localhost:8080/api/sync-customers?provider=wws&limit=5"

# Test sync single customer
curl "http://localhost:8080/api/sync-single-customer?email=test@example.com&provider=wws"
```

---

## Configuration

### Environment Variables

```env
# Customer Sync
CUSTOMER_SYNC_ENABLED=true
CUSTOMER_SYNC_INTERVAL=1h
CUSTOMER_SYNC_PROVIDER=wws
```

### Scheduler Configuration

Edit `config/scheduler.php` to adjust sync intervals:

```php
'customer_sync' => [
    'enabled' => true,
    'interval' => '1h',  // Options: 5m, 15m, 30m, 1h, 6h, 12h, daily
    'provider' => 'wws',
],
```

---

## Troubleshooting

### Customer Not Syncing

1. **Check provider capabilities:**
   ```php
   $provider = ProviderFactory::createErpProvider('wws');
   var_dump($provider->checkCapability('customer_search'));
   var_dump($provider->checkCapability('customer_create'));
   ```

2. **Check logs:**
   ```sql
   SELECT * FROM sync_logs 
   WHERE operation_type = 'customer_sync' 
   AND status = 'failed'
   ORDER BY created_at DESC;
   ```

3. **Check error queue:**
   ```sql
   SELECT * FROM error_queue 
   WHERE entity_type = 'customer'
   ORDER BY created_at DESC;
   ```

### Customer Duplicates

- System matches by email (primary)
- If duplicate emails exist, first match is used
- Check `customer_mappings` table for existing mappings

### Missing Customer Data

- Ensure ERP customer has email address
- Check adapter conversion in `WwsCustomerAdapter::toCoreModel()`
- Verify field mapping in adapters

---

## API Reference

### CustomerSyncService

```php
$syncService = new CustomerSyncService($erpProvider, $ecommerceProvider);

// Sync from ERP to Shopify
$result = $syncService->syncFromProvider('wws', $limit);

// Sync single customer from ERP to Shopify
$result = $syncService->syncToShopify($providerCustomer);

// Sync from Shopify to ERP
$result = $syncService->syncToProvider($shopifyCustomer, 'wws');
```

### Methods

- `syncFromProvider($providerName, $limit)` - Sync all customers from ERP
- `syncToProvider($shopifyCustomer, $providerName)` - Sync single customer to ERP
- `syncToShopify($providerCustomer)` - Sync single customer to Shopify (public)

---

## Next Steps

1. **Set up scheduled sync:**
   ```bash
   # Add to crontab
   */15 * * * * cd /path/to/wws && php cli/run-scheduler.php
   ```

2. **Test initial sync:**
   ```bash
   php cli/sync-customers.php --limit=10
   ```

3. **Monitor sync logs:**
   - Check `sync_logs` table
   - Monitor error queue
   - Review customer mappings

4. **Configure sync interval:**
   - Adjust `CUSTOMER_SYNC_INTERVAL` in `.env`
   - Update `config/scheduler.php` if needed

---

## Status

✅ **Customer sync is fully implemented and production-ready!**

All features are working:
- ✅ Bidirectional sync
- ✅ CLI commands
- ✅ Web endpoints
- ✅ Scheduler integration
- ✅ Error handling
- ✅ Customer mapping
- ✅ Order processing integration

