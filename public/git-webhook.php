<?php

/**
 * GitHub Auto-Deploy Webhook
 *
 * Lives in public/ (document root) so nginx serves it directly
 * without routing through index.php.
 *
 * Webhook URL: https://curion.techsystintel.com/git-webhook.php
 */

// One level up from public/ is the project root (where .env and git-deploy.sh live)
$rootPath     = dirname(__DIR__);
$logDir       = $rootPath . '/git-logs';
$logFile      = $logDir   . '/git-webhook.log';
$deployScript = $rootPath . '/git-deploy.sh';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// ── Minimal .env reader ───────────────────────────────────────────────────────
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

$secret    = readEnvValue('GIT_WEBHOOK_SECRET', $rootPath . '/.env');
$debugMode = readEnvValue('GIT_DEBUG', $rootPath . '/.env') === 'true';

function webhookLog(string $message, string $logFile, bool $always = false): void
{
    global $debugMode;
    if ($always || $debugMode) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
    }
}

// ── Secret must be configured ─────────────────────────────────────────────────
if (empty($secret)) {
    webhookLog('ERROR: GIT_WEBHOOK_SECRET not set in .env', $logFile, true);
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

webhookLog('Webhook received', $logFile);
webhookLog('Signature — expected: ' . $expectedSignature . ', received: ' . $receivedSignature, $logFile);

// ── Validate HMAC signature ───────────────────────────────────────────────────
if (!hash_equals($expectedSignature, $receivedSignature)) {
    webhookLog('ERROR: Invalid signature — aborting', $logFile, true);
    http_response_code(403);
    exit('Forbidden');
}

// ── Only deploy on pushes to main ─────────────────────────────────────────────
$data = json_decode($payload, true);
$ref  = $data['ref'] ?? '';

if ($ref !== 'refs/heads/main') {
    webhookLog('Skipping — ref is "' . $ref . '", not refs/heads/main', $logFile, true);
    http_response_code(200);
    exit('Skipped — not main branch');
}

// ── Trigger deploy script in background ──────────────────────────────────────
if (!file_exists($deployScript)) {
    webhookLog('ERROR: git-deploy.sh not found at ' . $deployScript, $logFile, true);
    http_response_code(500);
    exit('Deploy script missing');
}

$debugFlag = $debugMode ? ' --debug' : '';
$deployCmd = '/bin/bash ' . escapeshellarg($deployScript) . $debugFlag;

webhookLog('Triggering: ' . $deployCmd, $logFile);

shell_exec($deployCmd . ' > /dev/null 2>&1 &');

webhookLog('Deploy triggered successfully', $logFile, true);

http_response_code(200);
echo 'OK';
