# Web Server Configuration Guide

## Problem
If you're accessing URLs like:
```
http://localhost:8080/www/PROJECTS/AI/SHOPIFY/biggreen-egg/wws/public/api/...
```

You need to configure your web server so the `public` directory is the document root.

## Solutions

### Option 1: Configure Document Root (Recommended)

Point your web server's document root to the `public` directory.

#### For Apache (Virtual Host)
```apache
<VirtualHost *:80>
    ServerName wws.local
    DocumentRoot /Users/isk/ISK/www/PROJECTS/AI/SHOPIFY/biggreen-egg/wws/public
    
    <Directory /Users/isk/ISK/www/PROJECTS/AI/SHOPIFY/biggreen-egg/wws/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Then access: `https://curion.techsystintel.com/api/webhook/shopify-order`

#### For Nginx
```nginx
server {
    listen 80;
    server_name wws.local;
    root /Users/isk/ISK/www/PROJECTS/AI/SHOPIFY/biggreen-egg/wws/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### Option 2: Use PHP Built-in Server

Run from project root:
```bash
cd /Users/isk/ISK/www/PROJECTS/AI/SHOPIFY/biggreen-egg/wws
php -S localhost:8080 -t public
```

Then access: `http://localhost:8080/api/webhook/shopify-order`

### Option 3: Use Root .htaccess (Quick Fix)

A `.htaccess` file has been added to the project root that redirects requests to the `public` directory.

**Note:** This only works if:
- You're using Apache
- The project root is accessible via web server
- `mod_rewrite` is enabled

Access: `https://curion.techsystintel.com/api/webhook/shopify-order`

(Notice: no `/public/` in URL)

### Option 4: Update Shopify Webhook URL

If you can't change the web server configuration, update your Shopify webhook URL to include `/public/`:

```
https://your-domain.com/public/api/webhook/shopify-order
```

**Not recommended** as it exposes your directory structure.

---

## Recommended Setup

### Development (PHP Built-in Server)
```bash
cd /Users/isk/ISK/www/PROJECTS/AI/SHOPIFY/biggreen-egg/wws
php -S localhost:8080 -t public
```

**Webhook URL:** `http://localhost:8080/api/webhook/shopify-order`

### Production (Apache/Nginx)
Configure virtual host with document root pointing to `public/` directory.

**Webhook URL:** `https://your-domain.com/api/webhook/shopify-order`

---

## Testing Your Configuration

1. **Test if public directory is document root:**
   ```
   http://localhost:8080/api/test-connection.php
   ```
   Should work WITHOUT `/public/` in URL

2. **If you still need `/public/` in URL:**
   - Your web server document root is not configured correctly
   - Use Option 2 (PHP built-in server) for development
   - Or configure Apache/Nginx virtual host properly

---

## Current URL Structure

### ❌ Wrong (Current):
```
http://localhost:8080/www/PROJECTS/AI/SHOPIFY/biggreen-egg/wws/public/api/webhook/shopify-order
```

### ✅ Correct (After Configuration):
```
http://localhost:8080/api/webhook/shopify-order
```

Or if using a domain:
```
https://your-domain.com/api/webhook/shopify-order
```

