<?php

use App\Core\Routing\Router;
use App\Core\Factory\ProviderFactory;
use App\Core\Services\CustomerSyncService;
use App\Utils\LogHelper;

/**
 * API Routes
 */

$router = new Router();

// Health check
$router->get('/api/health', function() {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
});

// ── Import Shopify products into product_mappings ─────────────────────────────
// Reads every Shopify variant with a SKU and creates/updates a product_mappings
// row keyed by SKU. Useful after manually adding products to Shopify.
// No query params required — always processes the full catalogue.
$router->get('/api/import-shopify-mappings', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();

    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled']);
        exit;
    }

    set_time_limit(0);
    ini_set('max_execution_time', 0);

    try {
        $service = new \App\Core\Services\ShopifyMappingImportService();
        $stats   = $service->importAll();

        echo json_encode([
            'success' => true,
            'message' => 'Shopify product mappings imported successfully.',
            'stats'   => $stats,
            'hint'    => 'You can now run /api/sync-products?bundles_only=1 to sync bundle components.',
        ], JSON_PRETTY_PRINT);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
        ]);
    }
});

// Product sync endpoints
$router->get('/api/sync-products', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();

    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled']);
        exit;
    }

    // Allow unlimited execution time — full sync can take several minutes
    set_time_limit(0);
    ini_set('max_execution_time', 0);

    // ── Pagination params ────────────────────────────────────────────────────
    // ?limit=20              - how many products to fetch from WWS
    // ?offset=40             - skip the first N products (direct offset)
    // ?page=3&limit=20       - convenience: page is 1-based, offset = (page-1)*limit
    // ?bundles_only=1        - only sync products with stockManagement.id 101/102
    $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : null;

    if (isset($_GET['page']) && $limit !== null) {
        $page   = max(1, (int)$_GET['page']);
        $offset = ($page - 1) * $limit;
    } else {
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
        $page   = ($limit && $offset) ? (int)floor($offset / $limit) + 1 : 1;
    }

    $bundlesOnly = !empty($_GET['bundles_only']) && $_GET['bundles_only'] !== '0';

    try {
        $syncService = new \App\Core\Services\ProductSyncService();
        $result      = $syncService->syncAllProducts($limit, $offset, $bundlesOnly);

        // Compute next-page URL for convenience
        $nextOffset = $offset + ($limit ?? $result['fetched']);
        $nextPage   = $page + 1;

        echo json_encode([
            'success'    => true,
            'pagination' => [
                'limit'       => $limit,
                'offset'      => $offset,
                'page'        => $page,
                'fetched'     => $result['fetched'],
                'bundles_only' => $bundlesOnly,
                'next_page_url' => $limit
                    ? "/api/sync-products?limit={$limit}&page={$nextPage}" . ($bundlesOnly ? '&bundles_only=1' : '')
                    : null,
            ],
            'result'     => $result,
        ]);
    } catch (\Exception $e) {
        LogHelper::error('Product sync failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
        ]);
    }
});

$router->get('/api/sync-single-product', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();
    
    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled']);
        exit;
    }
    
    try {
        $syncService = new \App\Core\Services\ProductSyncService();
        
        if (isset($_GET['id'])) {
            $productId = $_GET['id'];
            $result = $syncService->syncProduct($productId);
        } elseif (isset($_GET['sku'])) {
            $sku = $_GET['sku'];
            $erpProvider = \App\Core\Factory\ProviderFactory::createErpProvider('wws');
            $product = $erpProvider->getProductBySku($sku);
            if (!$product || !isset($product['id'])) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => "Product not found with SKU: {$sku}",
                ]);
                exit;
            }
            $result = $syncService->syncProduct($product['id']);
        } else {
            LogHelper::warning('Missing required parameter for product sync', [
                'provided_params' => array_keys($_GET),
            ]);
            throw new \InvalidArgumentException('Either id or sku parameter is required');
        }
        
        echo json_encode([
            'success' => true,
            'result' => $result,
        ]);
    } catch (\Exception $e) {
        LogHelper::error('Single product sync failed', [
            'product_id' => $_GET['id'] ?? null,
            'sku' => $_GET['sku'] ?? null,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
});

// Customer sync endpoints
$router->get('/api/sync-customers', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();
    
    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled']);
        exit;
    }
    
    try {
        $providerName = $_GET['provider'] ?? 'wws';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        
        $erpProvider = ProviderFactory::createErpProvider($providerName);
        $syncService = new CustomerSyncService($erpProvider);
        $result = $syncService->syncFromProvider($providerName, $limit);
        
        echo json_encode([
            'success' => true,
            'result' => $result,
        ]);
    } catch (\Exception $e) {
        LogHelper::error('Customer sync failed', [
            'provider' => $providerName ?? 'unknown',
            'limit' => $limit ?? null,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
});

$router->get('/api/sync-single-customer', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();
    
    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled']);
        exit;
    }
    
    try {
        $providerName = $_GET['provider'] ?? 'wws';
        $erpProvider = \App\Core\Factory\ProviderFactory::createErpProvider($providerName);
        $syncService = new \App\Core\Services\CustomerSyncService($erpProvider);
        
        if (isset($_GET['id'])) {
            $customerId = $_GET['id'];
            $customer = $erpProvider->getCustomer($customerId);
            if (!$customer) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => "Customer not found with ID: {$customerId}",
                ]);
                exit;
            }
        } elseif (isset($_GET['email'])) {
            $email = $_GET['email'];
            $results = $erpProvider->searchCustomers($email, 0, 1);
            if (empty($results)) {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'error' => "Customer not found with email: {$email}",
                ]);
                exit;
            }
            $customer = $results[0];
        } else {
            LogHelper::warning('Missing required parameter for customer sync', [
                'provided_params' => array_keys($_GET),
            ]);
            throw new \InvalidArgumentException('Either id or email parameter is required');
        }
        
        $result = $syncService->syncToShopify($customer);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'result' => $result,
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Customer sync failed (no result returned)',
            ]);
        }
    } catch (\Exception $e) {
        LogHelper::error('Single customer sync failed', [
            'provider' => $providerName ?? 'unknown',
            'customer_id' => $_GET['id'] ?? null,
            'email' => $_GET['email'] ?? null,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
});

// Test connection
$router->get('/api/test-connection', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();
    
    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled']);
        exit;
    }
    
    $results = [
        'database' => false,
        'wws_api' => false,
        'shopify_api' => false,
    ];
    
    // Test database connection
    try {
        $db = \App\Database\Database::getInstance()->getConnection();
        $results['database'] = true;
    } catch (\Exception $e) {
        LogHelper::warning('Database connection test failed', [
            'error' => $e->getMessage(),
        ]);
        $results['database_error'] = $e->getMessage();
    }
    
    // Test WwsRestService API
    try {
        $wwsProductService = new \App\Api\WwsRestService\ProductService();
        $wwsProductService->searchProducts('*', 0, 1);
        $results['wws_api'] = true;
    } catch (\Exception $e) {
        LogHelper::warning('WWS API connection test failed', [
            'error' => $e->getMessage(),
        ]);
        $results['wws_api_error'] = $e->getMessage();
    }
    
    // Test Shopify API
    try {
        $shopifyProductService = new \App\Api\Shopify\ProductService();
        $shopifyProductService->getAllProducts(1, 1);
        $results['shopify_api'] = true;
    } catch (\Exception $e) {
        LogHelper::warning('Shopify API connection test failed', [
            'error' => $e->getMessage(),
        ]);
        $results['shopify_api_error'] = $e->getMessage();
    }
    
    $allConnected = $results['database'] && $results['wws_api'] && $results['shopify_api'];
    
    echo json_encode([
        'success' => $allConnected,
        'results' => $results,
    ]);
});

// Logs
$router->get('/api/logs/sync-logs', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();
    
    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled']);
        exit;
    }
    
    try {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $status = $_GET['status'] ?? null;
        $operationType = $_GET['operation_type'] ?? null;
        
        $logger = new \App\Utils\Logger();
        $logs = $logger->getSyncLogs($limit, $status, $operationType);
        
        echo json_encode([
            'success' => true,
            'count' => count($logs),
            'logs' => $logs,
        ]);
    } catch (\Exception $e) {
        LogHelper::error('Failed to get sync logs', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
});

$router->get('/api/logs/api-logs', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();
    
    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled']);
        exit;
    }
    
    try {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $apiType = $_GET['api_type'] ?? null;
        
        $logger = new \App\Utils\Logger();
        $logs = $logger->getApiLogs($limit, $apiType);
        
        echo json_encode([
            'success' => true,
            'count' => count($logs),
            'logs' => $logs,
        ]);
    } catch (\Exception $e) {
        LogHelper::error('Failed to get API logs', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
});

// Webhooks
$router->post('/api/webhook/shopify-order', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();
    
    // Read payload ONCE - php://input can only be read once!
    $payload = file_get_contents('php://input');
    
    LogHelper::info('Webhook received', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
        'payload_length' => strlen($payload),
    ]);
    
    if (!isset($config['webhook']['enabled']) || !$config['webhook']['enabled']) {
        LogHelper::warning('Webhook received but webhooks are disabled');
        http_response_code(403);
        echo json_encode(['error' => 'Webhooks are disabled']);
        exit;
    }
    
    // Validate HMAC signature
    $headers = getallheaders();
    $hmac = $headers['X-Shopify-Hmac-Sha256'] ?? $headers['x-shopify-hmac-sha256'] ?? null;
    
    if (!$hmac) {
        LogHelper::critical('Webhook missing HMAC signature', [
            'headers' => array_keys($headers),
        ]);
        http_response_code(401);
        echo json_encode(['error' => 'Missing HMAC signature']);
        exit;
    }
    
    try {
        $webhookService = new \App\Api\Shopify\WebhookService();
        LogHelper::debug('Validating webhook signature');
        $isValid = $webhookService->validateWebhook($payload, $hmac);
        
        if (!$isValid) {
            $secret = trim($config['webhook']['secret'] ?? '');
            $calculatedHmac = base64_encode(hash_hmac('sha256', $payload, $secret, true));
            
            LogHelper::critical('Invalid webhook signature', [
                'received_hmac_preview' => substr($hmac, 0, 40),
                'calculated_hmac_preview' => substr($calculatedHmac, 0, 40),
                'secret_configured' => !empty($secret),
                'secret_length' => strlen($secret),
            ]);
            http_response_code(401);
            echo json_encode(['error' => 'Invalid webhook signature']);
            exit;
        }
        
        LogHelper::debug('Webhook signature validated successfully');
    } catch (\Exception $validationException) {
        LogHelper::critical('Webhook validation exception', [
            'error' => $validationException->getMessage(),
            'trace' => $validationException->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Validation error: ' . $validationException->getMessage()]);
        exit;
    }
    
    // Parse order data
    $orderData = json_decode($payload, true);
    if (!$orderData) {
        $jsonError = json_last_error_msg();
        LogHelper::error('Invalid JSON payload in webhook', [
            'json_error' => $jsonError,
            'payload_preview' => substr($payload, 0, 500),
        ]);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload: ' . $jsonError]);
        exit;
    }
    
    LogHelper::info('Webhook order data parsed', [
        'order_id' => $orderData['id'] ?? 'N/A',
        'order_number' => $orderData['order_number'] ?? $orderData['name'] ?? 'N/A',
    ]);
    
    try {
        // Process webhook
        $webhookService = new \App\Api\Shopify\WebhookService();
        $processed = $webhookService->processOrderWebhook($orderData);
        
        // Add to order queue
        $orderQueueRepository = new \App\Database\Repository\OrderQueueRepository();
        $queueId = $orderQueueRepository->addOrder(
            $processed['shopify_order_id'],
            $processed['shopify_order_number'],
            $processed['order_data']
        );
        
        LogHelper::info('Order queued successfully', [
            'queue_id' => $queueId,
            'shopify_order_id' => $processed['shopify_order_id'],
            'shopify_order_number' => $processed['shopify_order_number'],
        ]);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Order queued for processing',
            'queue_id' => $queueId,
        ]);
    } catch (\Exception $e) {
        LogHelper::critical('Webhook processing failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'order_id' => $orderData['id'] ?? null,
        ]);
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
});

// Shopify products webhook — handles products/create, products/update, products/delete
$router->post('/api/webhook/shopify-product', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();

    // Read payload ONCE — php://input can only be read once
    $payload = file_get_contents('php://input');

    $headers = getallheaders();
    $topic   = $headers['X-Shopify-Topic'] ?? $headers['x-shopify-topic'] ?? null;

    LogHelper::info('Product webhook received', [
        'method'         => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'uri'            => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
        'topic'          => $topic,
        'payload_length' => strlen($payload),
    ]);

    if (!isset($config['webhook']['enabled']) || !$config['webhook']['enabled']) {
        LogHelper::warning('Product webhook received but webhooks are disabled');
        http_response_code(403);
        echo json_encode(['error' => 'Webhooks are disabled']);
        exit;
    }

    // HMAC validation — same secret as the order webhook (webhook.secret)
    $hmac = $headers['X-Shopify-Hmac-Sha256'] ?? $headers['x-shopify-hmac-sha256'] ?? null;
    if (!$hmac) {
        LogHelper::critical('Product webhook missing HMAC signature', [
            'topic'   => $topic,
            'headers' => array_keys($headers),
        ]);
        http_response_code(401);
        echo json_encode(['error' => 'Missing HMAC signature']);
        exit;
    }

    try {
        $webhookService = new \App\Api\Shopify\WebhookService();
        $isValid = $webhookService->validateWebhook($payload, $hmac);

        if (!$isValid) {
            LogHelper::critical('Invalid product webhook signature', [
                'topic'                 => $topic,
                'received_hmac_preview' => substr($hmac, 0, 40),
            ]);
            http_response_code(401);
            echo json_encode(['error' => 'Invalid webhook signature']);
            exit;
        }
    } catch (\Exception $validationException) {
        LogHelper::critical('Product webhook validation exception', [
            'topic' => $topic,
            'error' => $validationException->getMessage(),
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Validation error: ' . $validationException->getMessage()]);
        exit;
    }

    if (!$topic) {
        LogHelper::warning('Product webhook missing X-Shopify-Topic header');
        http_response_code(400);
        echo json_encode(['error' => 'Missing X-Shopify-Topic header']);
        exit;
    }

    // Parse payload
    $productData = json_decode($payload, true);
    if (!$productData) {
        $jsonError = json_last_error_msg();
        LogHelper::error('Invalid JSON payload in product webhook', [
            'topic'           => $topic,
            'json_error'      => $jsonError,
            'payload_preview' => substr($payload, 0, 500),
        ]);
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload: ' . $jsonError]);
        exit;
    }

    try {
        $result = $webhookService->processProductWebhook($topic, $productData);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'topic'   => $topic,
            'result'  => $result['result'] ?? null,
        ]);
    } catch (\Exception $e) {
        LogHelper::critical('Product webhook processing failed', [
            'topic'              => $topic,
            'shopify_product_id' => $productData['id'] ?? null,
            'error'              => $e->getMessage(),
            'trace'              => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
        ]);
    }
});

// UpPromote webhook endpoint — handles both validation pings and live events
// UpPromote sends a POST (with no signature) to validate the URL during subscription setup,
// then sends signed POSTs for real events.  GET is kept for manual browser testing.
$router->get('/api/webhook/uppromote-referral', function() {
    header('Content-Type: application/json');
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
});

$router->post('/api/webhook/uppromote-referral', function() {
    // Return 200 immediately — UpPromote requires this before accepting the subscription.
    // Flush the response to the client first, then process the payload below.
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level()) ob_end_flush();
        flush();
    }

    // Read raw payload — php://input can only be read once
    $payload = file_get_contents('php://input');
    $config  = \App\Core\Config::get();

    // Validation-only ping (no payload) — nothing more to do
    $data = json_decode($payload, true);
    if (empty($payload) || empty($data)) {
        exit;
    }

    if (empty($config['uppromote']['enabled'])) {
        LogHelper::warning('UpPromote webhook received but integration is disabled');
        exit;
    }

    LogHelper::info('UpPromote webhook received', [
        'payload_length' => strlen($payload),
    ]);

    // Verify HMAC-SHA256 signature (skip if secret not yet configured)
    $headers   = getallheaders();
    $signature = $headers['X-Uppromote-Signature'] ?? $headers['x-uppromote-signature'] ?? null;
    $secret    = $config['uppromote']['webhook_secret'] ?? '';

    if (!$signature) {
        LogHelper::warning('UpPromote webhook: missing signature header — skipping (validation ping or misconfigured sender)');
        exit;
    }

    if (!empty($secret) && !\App\Api\UpPromote\Client::verifyWebhookSignature($payload, $signature, $secret)) {
        LogHelper::critical('UpPromote webhook: invalid signature', [
            'received_preview' => substr($signature, 0, 16) . '...',
        ]);
        exit;
    }

    if (!$data || !isset($data['id'])) {
        LogHelper::error('UpPromote webhook: invalid or empty JSON payload');
        exit;
    }

    try {
        $service = new \App\Core\Services\AffiliateReferralService();
        $service->handleReferralNew($data);
    } catch (\Exception $e) {
        LogHelper::error('UpPromote webhook: processing exception', [
            'referral_id' => $data['id'] ?? null,
            'error'       => $e->getMessage(),
            'trace'       => $e->getTraceAsString(),
        ]);
    }
});

// ── TEST: Affiliate → Curion sync ───────────────────────────────────────────
// Runs the exact same linkReferral() code path as the UpPromote webhook.
// The only difference: affiliate_id comes from the URL instead of the POST body.
//
// Usage:
//   /api/test-affiliate-sync?shopify_order_id=12880542400886&affiliate_id=456
//
// Requires WEB_ENABLED=true in .env.
$router->get('/api/test-affiliate-sync', function() {
    header('Content-Type: application/json');
    $config = \App\Core\Config::get();

    if (!$config['web_enabled']) {
        http_response_code(403);
        echo json_encode(['error' => 'Web endpoints are disabled (WEB_ENABLED=false)']);
        return;
    }

    $shopifyOrderId = isset($_GET['shopify_order_id']) ? trim($_GET['shopify_order_id']) : null;
    $affiliateId    = isset($_GET['affiliate_id'])    ? (int)$_GET['affiliate_id']       : null;

    if (!$shopifyOrderId || !$affiliateId) {
        http_response_code(400);
        echo json_encode([
            'error'   => 'Both shopify_order_id and affiliate_id are required',
            'example' => '/api/test-affiliate-sync?shopify_order_id=12880542400886&affiliate_id=456',
        ]);
        return;
    }

    LogHelper::info('test-affiliate-sync: triggered', [
        'shopify_order_id' => $shopifyOrderId,
        'affiliate_id'     => $affiliateId,
    ]);

    try {
        // Delegate entirely to the same service used by the webhook.
        // dbId=0 and referralId=0 are safe — the service only uses referralId for
        // affiliate_referrals DB ops (update/delete), which are no-ops when no row exists.
        $service = new \App\Core\Services\AffiliateReferralService();
        $result  = $service->linkReferral(
            0,                           // dbId  — no DB row for this test call
            0,                           // referralId — no UpPromote referral record
            $shopifyOrderId,
            ['id' => $affiliateId]       // same structure the webhook passes from payload
        );

        $httpCode = $result['status'] === 'linked' ? 200
            : ($result['status'] === 'pending'     ? 202 : 500);

        http_response_code($httpCode);
        echo json_encode(array_merge(['success' => $result['status'] === 'linked'], $result));

    } catch (\Exception $e) {
        LogHelper::error('test-affiliate-sync: exception', [
            'shopify_order_id' => $shopifyOrderId,
            'affiliate_id'     => $affiliateId,
            'error'            => $e->getMessage(),
            'trace'            => $e->getTraceAsString(),
        ]);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
        ]);
    }
});

return $router;

