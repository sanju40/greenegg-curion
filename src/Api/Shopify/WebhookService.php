<?php

namespace App\Api\Shopify;

use App\Utils\LogHelper;

/**
 * Shopify Webhook Service
 * Handles webhook validation and processing
 */
class WebhookService
{
    private $webhookSecret;
    private $config;

    public function __construct()
    {
        $this->config = \App\Core\Config::get();
        $this->webhookSecret = trim($this->config['webhook']['secret'] ?? '');
    }

    /**
     * Validate webhook HMAC signature
     */
    public function validateWebhook($payload, $hmac)
    {
        if (empty($this->webhookSecret)) {
            return false;
        }

        // Calculate HMAC using the raw payload (must be exact raw body)
        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $payload, $this->webhookSecret, true)
        );

        // Use hash_equals for timing-safe comparison
        $isValid = hash_equals($calculatedHmac, $hmac);
        
        return $isValid;
    }
    
    /**
     * Get webhook secret (for debugging)
     */
    public function getSecretLength()
    {
        return strlen($this->webhookSecret);
    }

    /**
     * Process order webhook
     */
    public function processOrderWebhook($orderData)
    {
        // Validate required fields
        if (!isset($orderData['id']) || !isset($orderData['order_number'])) {
            LogHelper::error('Invalid order data in webhook', [
                'order_data_keys' => array_keys($orderData),
            ]);
            throw new \InvalidArgumentException('Invalid order data: missing id or order_number');
        }

        return [
            'shopify_order_id' => (string)$orderData['id'],
            'shopify_order_number' => (string)$orderData['order_number'],
            'order_data' => $orderData,
        ];
    }

    /**
     * Process a products/* webhook delivered by Shopify.
     *
     * Topic dispatch:
     *   products/create, products/update → upsert mapping rows + persist tags
     *   products/delete                  → drop mapping rows by Shopify product ID
     *
     * Unknown topics are logged and a no-op result returned (Shopify still gets
     * a 200 in the route so it doesn't retry).
     *
     * @param string $topic       The X-Shopify-Topic header value
     * @param array  $productData Decoded JSON webhook body
     * @return array              Action summary for logging/route response
     */
    public function processProductWebhook(string $topic, array $productData): array
    {
        if (!isset($productData['id'])) {
            LogHelper::error('Invalid product data in webhook', [
                'topic'              => $topic,
                'product_data_keys'  => array_keys($productData),
            ]);
            throw new \InvalidArgumentException('Invalid product data: missing id');
        }

        $shopifyProductId = (string)$productData['id'];

        switch ($topic) {
            case 'products/create':
            case 'products/update':
                $service = new \App\Core\Services\ShopifyMappingImportService();
                $result  = $service->upsertFromShopifyPayload($productData);

                LogHelper::info('Shopify product webhook processed', [
                    'topic'              => $topic,
                    'shopify_product_id' => $shopifyProductId,
                    'result'             => $result,
                ]);

                return [
                    'topic'              => $topic,
                    'shopify_product_id' => $shopifyProductId,
                    'result'             => $result,
                ];

            case 'products/delete':
                $repo = new \App\Database\Repository\ProductMappingRepository();
                $deleted = $repo->deleteByShopifyProductId($shopifyProductId);

                LogHelper::info('Shopify product delete webhook processed', [
                    'topic'              => $topic,
                    'shopify_product_id' => $shopifyProductId,
                    'deleted'            => $deleted,
                ]);

                return [
                    'topic'              => $topic,
                    'shopify_product_id' => $shopifyProductId,
                    'result'             => ['action' => 'deleted', 'success' => $deleted],
                ];

            default:
                LogHelper::warning('Shopify product webhook: unknown topic', [
                    'topic'              => $topic,
                    'shopify_product_id' => $shopifyProductId,
                ]);
                return [
                    'topic'              => $topic,
                    'shopify_product_id' => $shopifyProductId,
                    'result'             => ['action' => 'ignored', 'reason' => 'unknown_topic'],
                ];
        }
    }
}

