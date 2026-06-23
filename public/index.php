<?php

/**
 * Web Entry Point
 * Laravel-like routing system
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

// Webhook endpoints must always be reachable — Shopify and UpPromote require a
// reliable URL regardless of whether the admin web interface is enabled.
// Only non-webhook routes are restricted by WEB_ENABLED.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$isWebhook   = strpos($requestPath, '/api/webhook/') !== false;

if (!$config['web_enabled'] && !$isWebhook) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Web endpoints are disabled']);
    exit;
}

// Load routes
$router = require __DIR__ . '/../src/Core/Routing/routes/api.php';

// Dispatch request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';

$router->dispatch($method, $uri);

