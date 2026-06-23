<?php

/**
 * CLI: Manage UpPromote webhook subscriptions
 *
 * Commands:
 *   subscribe    Register a new webhook for a given event
 *   list         List all active webhook subscriptions
 *   delete       Delete a webhook subscription by target URL + event
 *
 * Usage:
 *   php cli/uppromote-webhook.php subscribe --event=referral.new --url=https://your-domain.com/api/webhook/uppromote-referral
 *   php cli/uppromote-webhook.php list
 *   php cli/uppromote-webhook.php delete  --event=referral.new --url=https://your-domain.com/api/webhook/uppromote-referral
 *
 * After a successful subscribe, copy the printed secret_key into UPPROMOTE_WEBHOOK_SECRET in .env
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Utils\LogHelper;

// ── Supported events (UpPromote API v2) ──────────────────────────────────────
const SUPPORTED_EVENTS = [
    'referral.new',
    'referral.approved',
    'referral.denied',
    'referral.status-changed',
    'affiliate.new',
    'affiliate.approved',
    'affiliate.inactive',
    'affiliate.status-changed',
    'payment.paid',
];

const UPPROMOTE_API_BASE = 'https://aff-api.uppromote.com/api/v2';

// ── Parse arguments ───────────────────────────────────────────────────────────
$command = $argv[1] ?? null;

if (!$command || !in_array($command, ['subscribe', 'list', 'delete'], true)) {
    echo usage();
    exit(1);
}

// Manual parser: supports --key=value and --key value (getopt stops at positional args)
$options = [];
for ($i = 2; $i < count($argv); $i++) {
    if (preg_match('/^--([a-zA-Z][\w-]*)=(.+)$/', $argv[$i], $m)) {
        $options[$m[1]] = $m[2];
    } elseif (preg_match('/^--([a-zA-Z][\w-]*)$/', $argv[$i], $m) && isset($argv[$i + 1]) && $argv[$i + 1][0] !== '-') {
        $options[$m[1]] = $argv[++$i];
    }
}

$event     = $options['event'] ?? null;
$targetUrl = $options['url']   ?? null;

// ── Load config ───────────────────────────────────────────────────────────────
$config = \App\Core\Config::get();
$apiKey = $config['uppromote']['api_key'] ?? '';

if (empty($apiKey)) {
    echo "ERROR: UPPROMOTE_API_KEY is not set in .env\n";
    exit(1);
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
switch ($command) {
    case 'subscribe':
        cmdSubscribe($apiKey, $event, $targetUrl);
        break;
    case 'list':
        cmdList($apiKey, $event);
        break;
    case 'delete':
        cmdDelete($apiKey, $event, $targetUrl);
        break;
}

// ── Commands ──────────────────────────────────────────────────────────────────

function cmdSubscribe(string $apiKey, ?string $event, ?string $targetUrl): void
{
    if (!$event || !$targetUrl) {
        echo "ERROR: --event and --url are required for subscribe\n";
        echo usage();
        exit(1);
    }

    if (!in_array($event, SUPPORTED_EVENTS, true)) {
        echo "ERROR: unsupported event '{$event}'\n";
        echo "Supported events:\n";
        foreach (SUPPORTED_EVENTS as $e) {
            echo "  {$e}\n";
        }
        exit(1);
    }

    echo "Subscribing to '{$event}'...\n";
    echo "Target URL: {$targetUrl}\n\n";

    $response = apiRequest($apiKey, 'POST', 'webhook-subscriptions', [
        'target_url' => $targetUrl,
        'event'      => $event,
    ]);

    if (!$response || ($response['status'] ?? 0) !== 200) {
        echo "ERROR: subscription failed.\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }

    $data = $response['data'] ?? [];

    echo "✓ Subscription created successfully!\n\n";
    echo str_repeat('-', 50) . "\n";
    echo "Event:       " . ($data['event']      ?? '-') . "\n";
    echo "Target URL:  " . ($data['target_url'] ?? '-') . "\n";
    echo "Status:      " . ($data['status']     ?? '-') . "\n";
    echo "Version:     " . ($data['version']    ?? '-') . "\n";
    echo "Created at:  " . ($data['created_at'] ?? '-') . "\n";
    echo str_repeat('-', 50) . "\n\n";

    $secretKey = $data['secret_key'] ?? null;
    if ($secretKey) {
        echo "SECRET KEY (copy this into UPPROMOTE_WEBHOOK_SECRET in .env):\n";
        echo "  {$secretKey}\n\n";
        echo "Add to .env:\n";
        echo "  UPPROMOTE_WEBHOOK_SECRET={$secretKey}\n\n";
    }
}

function cmdList(string $apiKey, ?string $filterEvent): void
{
    echo "Fetching webhook subscriptions...\n\n";

    $params = [];
    if ($filterEvent) {
        $params['event'] = $filterEvent;
    }

    $response = apiRequest($apiKey, 'GET', 'webhook-subscriptions', null, $params);

    if (!$response || ($response['status'] ?? 0) !== 200) {
        echo "ERROR: failed to fetch subscriptions.\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }

    $subscriptions = $response['data'] ?? [];

    if (empty($subscriptions)) {
        echo "No webhook subscriptions found.\n";
        return;
    }

    echo "Found " . count($subscriptions) . " subscription(s):\n\n";
    echo str_repeat('-', 70) . "\n";

    foreach ($subscriptions as $i => $sub) {
        $n = $i + 1;
        echo "[{$n}] Event:      " . ($sub['event']      ?? '-') . "\n";
        echo "    URL:        " . ($sub['target_url'] ?? '-') . "\n";
        echo "    Status:     " . ($sub['status']     ?? '-') . "\n";
        echo "    Version:    " . ($sub['version']    ?? '-') . "\n";
        echo "    Created:    " . ($sub['created_at'] ?? '-') . "\n";
        echo "    Secret:     " . ($sub['secret_key'] ?? '-') . "\n";
        echo str_repeat('-', 70) . "\n";
    }
}

function cmdDelete(string $apiKey, ?string $event, ?string $targetUrl): void
{
    if (!$event || !$targetUrl) {
        echo "ERROR: --event and --url are required for delete\n";
        echo usage();
        exit(1);
    }

    echo "Deleting subscription for '{$event}' → {$targetUrl}...\n\n";

    $response = apiRequest($apiKey, 'DELETE', 'webhook-subscriptions', [
        'target_url' => $targetUrl,
        'event'      => $event,
    ]);

    if (!$response) {
        echo "ERROR: request failed or returned empty response.\n";
        exit(1);
    }

    $status = $response['status'] ?? 0;
    if ($status === 200) {
        echo "✓ Subscription deleted successfully.\n";
    } else {
        echo "ERROR: deletion may have failed.\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n";
        exit(1);
    }
}

// ── HTTP helper ───────────────────────────────────────────────────────────────

function apiRequest(string $apiKey, string $method, string $endpoint, ?array $body, array $params = []): ?array
{
    $url = UPPROMOTE_API_BASE . '/' . ltrim($endpoint, '/');

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init();

    $headers = [
        'Authorization: ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL             => $url,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_ENCODING        => '',
        CURLOPT_MAXREDIRS       => 10,
        CURLOPT_TIMEOUT         => 30,
        CURLOPT_CONNECTTIMEOUT  => 10,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_HTTPHEADER      => $headers,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
    }

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo "CURL error: {$curlError}\n";
        return null;
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $message = $decoded['message'] ?? $response;
        if (is_array($message)) {
            $message = json_encode($message);
        }
        echo "HTTP {$httpCode}: {$message}\n";
        return $decoded;
    }

    return $decoded;
}

// ── Usage ─────────────────────────────────────────────────────────────────────

function usage(): string
{
    $events = implode("\n  ", SUPPORTED_EVENTS);
    return <<<TXT

Usage:
  php cli/uppromote-webhook.php <command> [options]

Commands:
  subscribe  --event=<event> --url=<url>   Register a new webhook
  list       [--event=<event>]             List all subscriptions (optionally filter by event)
  delete     --event=<event> --url=<url>   Remove a webhook subscription

Supported events:
  {$events}

Examples:
  php cli/uppromote-webhook.php subscribe --event=referral.new --url=https://your-domain.com/api/webhook/uppromote-referral
  php cli/uppromote-webhook.php list
  php cli/uppromote-webhook.php list --event=referral.new
  php cli/uppromote-webhook.php delete --event=referral.new --url=https://your-domain.com/api/webhook/uppromote-referral

TXT;
}
