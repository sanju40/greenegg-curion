<?php

/**
 * CLI: Preview order payload for WWS/Curion (no API writes)
 * Usage:
 *   php cli/preview-order-payload.php --order-id=13099373429110
 *   php cli/preview-order-payload.php --order-number=1470
 *   php cli/preview-order-payload.php --from-queue --order-id=13099373429110
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Config;
use App\Core\Services\OrderProcessingService;
use App\Api\Shopify\OrderService;
use App\Database\Repository\OrderQueueRepository;

$config = Config::get();

if (!$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

$options = getopt('', ['order-id:', 'order-number:', 'from-queue']);
$orderId = $options['order-id'] ?? null;
$orderNumber = $options['order-number'] ?? null;
$fromQueue = isset($options['from-queue']);

if (!$orderId && !$orderNumber) {
    echo "Error: --order-id or --order-number is required\n";
    echo "Usage: php cli/preview-order-payload.php --order-id=13099373429110\n";
    exit(1);
}

echo "Preview order payload (dry-run — nothing sent to WWS/Curion)\n";
if ($orderId) {
    echo "Shopify order ID: {$orderId}\n";
}
if ($orderNumber) {
    echo "Order number: {$orderNumber}\n";
}
echo "\n";

try {
    $orderData = null;

    if ($fromQueue || $orderId) {
        $repo = new OrderQueueRepository();
        $row = $orderId
            ? $repo->findByShopifyOrderId((string) $orderId)
            : null;

        if ($row && !empty($row['order_data'])) {
            $orderData = json_decode($row['order_data'], true);
            echo "Source: order_queue\n";
        }
    }

    if (!$orderData) {
        $orderService = new OrderService();
        if ($orderId) {
            $orderData = $orderService->getOrder($orderId);
        } elseif ($orderNumber) {
            $name = '#' . ltrim((string) $orderNumber, '#');
            $orders = $orderService->getOrders(['name' => $name, 'status' => 'any', 'limit' => 1]);
            $orderData = $orders[0] ?? null;
        }
        echo "Source: Shopify API\n";
    }

    if (!$orderData) {
        echo "Error: Order not found\n";
        exit(1);
    }

    $rawLineCount = count($orderData['line_items'] ?? []);

    $service = new OrderProcessingService(null, null, null, true);
    $result = $service->processOrder($orderData);

    echo "\n=== Dry-run result ===\n";
    echo "Status: " . ($result['status'] ?? 'unknown') . "\n";
    echo "Line items (input): {$rawLineCount}\n";
    echo "Line items (after bundle filter): " . count($result['order_data']['line_items'] ?? []) . "\n";

    $payload = $result['provider_payload'] ?? null;
    if (!$payload) {
        $queueRow = (new OrderQueueRepository())->findByShopifyOrderId((string) ($orderData['id'] ?? ''));
        if ($queueRow && !empty($queueRow['provider_payload'])) {
            $payload = json_decode($queueRow['provider_payload'], true);
        }
    }

    if ($payload) {
        echo "\n=== WWS transaction payload (dry-run preview) ===\n";
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

        $storedInQueue = (new OrderQueueRepository())->findByShopifyOrderId((string) ($orderData['id'] ?? ''));
        if ($storedInQueue && !empty($storedInQueue['provider_payload'])) {
            echo "\n(Also stored in order_queue.provider_payload)\n";
        }
    } else {
        echo "\nNo payload generated. Check application logs.\n";
    }

    exit(0);
} catch (\Exception $e) {
    \App\Utils\LogHelper::error('Order payload preview failed', [
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'error' => $e->getMessage(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
