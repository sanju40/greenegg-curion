# Auto-Deploy Setup Guide

Every push to the `main` branch on GitHub automatically deploys to the production server. This is done via two files:

| File | Purpose |
|---|---|
| `git-webhook.php` | Receives the GitHub webhook, validates the signature, triggers the deploy |
| `git-deploy.sh` | Runs `git pull`, `composer install`, and fixes permissions |

---

## Webhook Endpoint

```
https://curion.techsystintel.com/git-webhook.php
```

This file sits in the web root (`public_html/`) and is served directly by Apache — it does **not** go through the application router.

---

## One-Time Server Setup

These steps are run **once** on the production server via SSH. They do not need to be repeated on future deployments.

### Step 1 — Make the deploy script executable

```bash
chmod +x /home/admin/web/curion.techsystintel.com/public_html/git-deploy.sh
```

### Step 2 — Generate a webhook secret

Generate a random secret string (this will be shared between GitHub and your server):

```bash
openssl rand -hex 32
```

Copy the output. You will use it in Step 3 and Step 4.

### Step 3 — Add the secret to .env on the server

Open the server's `.env` file:

```bash
nano /home/admin/web/curion.techsystintel.com/public_html/.env
```

Add or update these two lines at the bottom:

```env
GIT_WEBHOOK_SECRET=paste_your_generated_secret_here
GIT_DEBUG=false
```

Set `GIT_DEBUG=true` temporarily if you need to troubleshoot — it writes every webhook request and deploy step to `git-logs/git-webhook.log`.

### Step 4 — Add the webhook in GitHub

1. Go to **GitHub → `sanju40/greenegg-curion` → Settings → Webhooks**
2. Click **Add webhook**
3. Fill in:

| Field | Value |
|---|---|
| Payload URL | `https://curion.techsystintel.com/git-webhook.php` |
| Content type | `application/json` |
| Secret | *(paste the same secret from Step 2)* |
| Which events | **Just the push event** |
| Active | ✅ checked |

4. Click **Add webhook**

GitHub will send a test ping. The server will respond with `200 OK`.

### Step 5 — Verify SSH access for git pull (if needed)

The deploy script calls `git pull origin main`. For this to work without a password prompt, the server must be authenticated with GitHub.

**Check if it already works:**

```bash
cd /home/admin/web/curion.techsystintel.com/public_html
git pull origin main
```

If it asks for a password or shows a permission error, set up a deploy key:

```bash
# Generate a deploy key on the server
ssh-keygen -t ed25519 -f /home/admin/.ssh/greenegg-curion-deploy -C "curion-autodeploy" -N ""

# Show the public key — copy this
cat /home/admin/.ssh/greenegg-curion-deploy.pub
```

Then in GitHub → `sanju40/greenegg-curion` → **Settings → Deploy keys → Add deploy key**:
- Title: `curion-server`
- Key: *(paste the public key)*
- Allow write access: **No** (read-only is enough)

Finally, uncomment this line in `git-deploy.sh` on the server and set the correct path:

```bash
export GIT_SSH_COMMAND='ssh -i /home/admin/.ssh/greenegg-curion-deploy -o IdentitiesOnly=yes'
```

---

## How It Works (Flow)

```
Developer pushes to main on GitHub
        │
        ▼
GitHub sends POST to https://curion.techsystintel.com/git-webhook.php
        │
        ▼
git-webhook.php validates HMAC-SHA256 signature using GIT_WEBHOOK_SECRET
        │
        ├── Invalid signature → 403 Forbidden (logged)
        ├── Push is not to main → 200 Skipped
        │
        ▼
git-deploy.sh runs in background (server does not wait)
        │
        ├── git reset --hard
        ├── git pull origin main
        ├── composer install --no-dev --optimize-autoloader
        └── chmod -R 775 logs/
        │
        ▼
GitHub gets 200 OK immediately (deploy runs after response)
```

---

## Deploy Logs

All deploy activity is logged to:

```
/home/admin/web/curion.techsystintel.com/public_html/git-logs/deploy.log
/home/admin/web/curion.techsystintel.com/public_html/git-logs/git-webhook.log
```

These files are **gitignored** — they exist only on the server and are never committed.

To watch the deploy log in real time after a push:

```bash
tail -f /home/admin/web/curion.techsystintel.com/public_html/git-logs/deploy.log
```

---

## Testing the Webhook Manually

You can re-send the last GitHub webhook ping from the GitHub UI:

1. GitHub → repo → **Settings → Webhooks → click your webhook**
2. Scroll to **Recent Deliveries**
3. Click any delivery → **Redeliver**

Or trigger a deploy directly on the server:

```bash
cd /home/admin/web/curion.techsystintel.com/public_html
bash git-deploy.sh --debug
```

---

## Troubleshooting

| Symptom | Check |
|---|---|
| GitHub shows `503` or connection refused | Apache/PHP is not running, or the domain is wrong |
| GitHub shows `403 Forbidden` | `GIT_WEBHOOK_SECRET` in `.env` does not match the secret in GitHub |
| GitHub shows `500` | `GIT_WEBHOOK_SECRET` is missing from `.env`, or `git-deploy.sh` is not found |
| GitHub shows `200 OK` but code didn't update | Check `git-logs/deploy.log` — likely a `git pull` permission error |
| `git pull` asks for password | Deploy key not set up (see Step 5 above) |
| `composer: command not found` | Use full path in `git-deploy.sh`: `/usr/local/bin/composer` |
| No log files created | Run `bash git-deploy.sh --debug` manually and check output |

---

## What Is NOT Touched on Deploy

The deploy script only runs `git pull` and `composer install`. It intentionally does **not**:

- Modify the `.env` file (your production credentials are safe)
- Run database migrations (run `php cli/migrate.php` manually after schema changes)
- Restart PHP-FPM or Apache (not needed for PHP file changes)
- Touch the `vendor/` directory beyond what `composer install` needs
