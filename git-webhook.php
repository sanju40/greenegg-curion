<?php

/**
 * GitHub Auto-Deploy Webhook
 *
 * Receives a push event from GitHub, validates the HMAC-SHA256 signature,
 * and triggers git-deploy.sh in the background.
 *
 * Setup:
 *   1. Add GIT_WEBHOOK_SECRET=<random_secret> to your .env file
 *   2. In GitHub → repo → Settings → Webhooks → Add webhook:
 *        Payload URL : https://curion.techsystintel.com/git-webhook.php
 *        Content type: application/json
 *        Secret      : <same random secret>
 *        Events      : Just the push event
 *   3. chmod +x git-deploy.sh  (run once on the server)
 */

$rootPath   = __DIR__;
$logDir     = $rootPath . '/git-logs';
$logFile    = $logDir   . '/git-webhook.log';
$deployScript = $rootPath . '/git-deploy.sh';

// Ensure log directory exists
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ── Minimal .env reader (avoids loading the full app bootstrap) ───────────────
function readEnvValue(string $key, string $envFile): ?string
{
    if (!file_exists($envFile)) {
        return null;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) === $key) {
            return trim($v);
        }
    }
    return null;
}

$secret      = readEnvValue('GIT_WEBHOOK_SECRET', $rootPath . '/.env');
$debugMode   = readEnvValue('GIT_DEBUG', $rootPath . '/.env') === 'true';

function webhookLog(string $message, string $logFile, bool $enabled = true): void
{
    if ($enabled) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
    }
}

// ── Validate secret is configured ────────────────────────────────────────────
if (empty($secret)) {
    webhookLog('ERROR: GIT_WEBHOOK_SECRET not set in .env', $logFile);
    http_response_code(500);
    exit('Server config error');
}

// ── Read payload and headers ──────────────────────────────────────────────────
$payload  = file_get_contents('php://input');
$headers  = getallheaders();

$receivedSignature = $headers['X-Hub-Signature-256']
    ?? $headers['x-hub-signature-256']
    ?? '';

$expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if ($debugMode) {
    webhookLog('Webhook received', $logFile);
    webhookLog('Signature check — expected: ' . $expectedSignature . ', received: ' . $receivedSignature, $logFile);
}

// ── Validate HMAC signature ───────────────────────────────────────────────────
if (!hash_equals($expectedSignature, $receivedSignature)) {
    webhookLog('ERROR: Invalid signature — aborting', $logFile);
    http_response_code(403);
    exit('Forbidden');
}

// ── Only deploy on pushes to main ─────────────────────────────────────────────
$data = json_decode($payload, true);
$ref  = $data['ref'] ?? '';

if ($ref !== 'refs/heads/main') {
    webhookLog('Skipping — ref is "' . $ref . '", not refs/heads/main', $logFile);
    http_response_code(200);
    exit('Skipped — not main branch');
}

// ── Trigger deploy script in background ──────────────────────────────────────
if (!file_exists($deployScript)) {
    webhookLog('ERROR: git-deploy.sh not found at ' . $deployScript, $logFile);
    http_response_code(500);
    exit('Deploy script missing');
}

$debugFlag  = $debugMode ? ' --debug' : '';
$deployCmd  = '/bin/bash ' . escapeshellarg($deployScript) . $debugFlag;

webhookLog('Triggering: ' . $deployCmd, $logFile, $debugMode);

shell_exec($deployCmd . ' > /dev/null 2>&1 &');

webhookLog('Deploy triggered successfully', $logFile, $debugMode);

http_response_code(200);
echo 'OK';
