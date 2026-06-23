# Webhook Debugging Guide

## Problem: Webhook Not Receiving Data

If Shopify webhooks aren't showing up in your logs, use these debugging tools.

## Step 1: Test if Endpoint is Accessible

Visit or curl the test endpoint:
```
GET https://arrival-development-kids-caution.trycloudflare.com/api/webhook/test.php
```

This will show:
- If the endpoint is accessible
- Server information
- Request headers
- Configuration status

## Step 2: Check Webhook Log File

All webhook requests are logged to: `webhook.log` (in project root)

View recent logs via browser:
```
GET https://arrival-development-kids-caution.trycloudflare.com/api/webhook/debug.php?lines=100
```

Or check the file directly:
```bash
tail -f webhook.log
```

## Step 3: Verify Webhook Configuration

### In Shopify Admin:
1. Go to Settings → Notifications → Webhooks
2. Check your webhook:
   - URL: `https://arrival-development-kids-caution.trycloudflare.com/api/webhook/shopify-order.php`
   - Event: Order creation
   - Format: JSON
   - API version: Latest

### In Your .env File:
```env
SHOPIFY_WEBHOOK_SECRET=your_webhook_secret_here
WEB_ENABLED=true
```

Make sure `SHOPIFY_WEBHOOK_SECRET` matches the secret in Shopify webhook settings.

## Step 4: Test with Manual Request

Use curl to simulate a webhook:

```bash
curl -X POST https://arrival-development-kids-caution.trycloudflare.com/api/webhook/shopify-order.php \
  -H "Content-Type: application/json" \
  -H "X-Shopify-Hmac-Sha256: test" \
  -d '{"test": "data"}'
```

Then check `webhook.log` to see if it was received.

## Step 5: Check Database Logs

Check if orders are being queued:

Via browser:
```
GET https://arrival-development-kids-caution.trycloudflare.com/api/logs/sync-logs.php?operation_type=order_processing
```

Or query database:
```sql
SELECT * FROM order_queue ORDER BY created_at DESC LIMIT 10;
SELECT * FROM sync_logs WHERE operation_type = 'order_processing' ORDER BY created_at DESC LIMIT 10;
```

## Common Issues

### 1. Webhook Not Reaching Server
**Symptoms:** No entries in `webhook.log`

**Solutions:**
- Check if Cloudflare tunnel is running
- Verify URL is correct in Shopify
- Test with `/api/webhook/test.php` to verify accessibility
- Check firewall/network restrictions

### 2. HMAC Validation Failing
**Symptoms:** Entries in log show "Invalid webhook signature"

**Solutions:**
- Verify `SHOPIFY_WEBHOOK_SECRET` in `.env` matches Shopify
- Check if header name is correct (case-sensitive)
- Ensure webhook secret was copied correctly

### 3. JSON Parsing Errors
**Symptoms:** "Invalid JSON payload" in logs

**Solutions:**
- Check payload preview in `webhook.log`
- Verify Shopify is sending JSON format
- Check for encoding issues

### 4. Orders Not Being Queued
**Symptoms:** Webhook received but no database entries

**Solutions:**
- Check database connection
- Verify `order_queue` table exists
- Check for database errors in log file
- Verify database credentials in `.env`

## Log File Location

The webhook log file is at:
```
/Users/isk/ISK/www/PROJECTS/AI/SHOPIFY/biggreen-egg/wws/webhook.log
```

Each webhook request logs:
- Timestamp
- Request method and URI
- All headers
- Payload size
- HMAC validation status
- Order information
- Success/error messages

## Monitoring

### Real-time Monitoring
```bash
# Watch log file in real-time
tail -f webhook.log

# Or filter for errors only
tail -f webhook.log | grep ERROR
```

### Check Recent Webhooks
```bash
# Last 20 lines
tail -20 webhook.log

# Or via browser
https://arrival-development-kids-caution.trycloudflare.com/api/webhook/debug.php?lines=20
```

## Shopify Webhook Delivery Status

In Shopify Admin → Settings → Notifications → Webhooks:
- Click on your webhook
- Check "Recent deliveries" tab
- Look for:
  - ✅ Success (200 response)
  - ❌ Failed (check error message)
  - ⏱️ Pending (webhook queued but not delivered)

## Next Steps After Debugging

Once webhooks are working:
1. Monitor `webhook.log` for any issues
2. Check `order_queue` table regularly
3. Process pending orders: `php cli/process-pending-orders.php`
4. Set up alerts for failed webhooks (future enhancement)

