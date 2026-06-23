<?php

namespace App\Core\ErrorQueue;

use App\Database\Database;
use App\Core\Factory\ProviderFactory;
use App\Core\Services\ProductSyncService;
use App\Core\Services\CustomerSyncService;
use App\Core\Services\OrderProcessingService;
use App\Utils\LogHelper;
use PDO;

/**
 * Error Queue Processor
 * Processes failed operations from error queue
 */
class ErrorQueueProcessor
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Process pending errors
     * @param int $limit Maximum number of errors to process
     * @return array Statistics
     */
    public function processPendingErrors(int $limit = 50): array
    {
        $stats = [
            'processed' => 0,
            'resolved' => 0,
            'failed' => 0,
        ];

        $stmt = $this->db->prepare("
            SELECT * FROM error_queue 
            WHERE status = 'pending' 
            AND next_retry_at <= NOW()
            AND retry_count < max_retries
            ORDER BY next_retry_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $errors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($errors as $error) {
            try {
                $this->processError($error);
                $stats['resolved']++;
            } catch (\Exception $e) {
                LogHelper::error('Error queue processing failed', [
                    'error_id' => $error['id'] ?? null,
                    'entity_type' => $error['entity_type'] ?? null,
                    'entity_id' => $error['entity_id'] ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->handleRetry($error, $e);
                $stats['failed']++;
            }
            $stats['processed']++;
        }

        return $stats;
    }

    /**
     * Process a single error
     * @param array $error
     */
    private function processError(array $error): void
    {
        // Mark as retrying
        $stmt = $this->db->prepare("
            UPDATE error_queue 
            SET status = 'retrying'
            WHERE id = ?
        ");
        $stmt->execute([$error['id']]);

        $payload = json_decode($error['payload'], true);
        $entityType = $error['entity_type'];
        $operation = $error['operation'];

        // Retry the operation based on entity type
        switch ($entityType) {
            case 'product':
                $this->retryProductOperation($operation, $payload);
                break;
            case 'customer':
                $this->retryCustomerOperation($operation, $payload);
                break;
            case 'order':
                $this->retryOrderOperation($operation, $payload);
                break;
        }

        // Mark as resolved
        $stmt = $this->db->prepare("
            UPDATE error_queue 
            SET status = 'resolved'
            WHERE id = ?
        ");
        $stmt->execute([$error['id']]);
    }

    /**
     * Retry product operation
     */
    private function retryProductOperation(string $operation, array $payload): void
    {
        if ($operation === 'sync') {
            $productId = $payload['product_id'] ?? null;
            if ($productId) {
                $syncService = new ProductSyncService();
                $syncService->syncProduct($productId);
            }
        }
    }

    /**
     * Retry customer operation
     */
    private function retryCustomerOperation(string $operation, array $payload): void
    {
        $providerName = $payload['provider'] ?? 'wws';
        $provider = ProviderFactory::createErpProvider($providerName);
        $customerSyncService = new CustomerSyncService($provider);
        
        if ($operation === 'sync_to_shopify') {
            // Sync customer from provider to Shopify
            $providerCustomer = $payload['provider_customer'] ?? null;
            if ($providerCustomer) {
                $customerSyncService->syncToShopify($providerCustomer);
            }
        } elseif ($operation === 'sync_to_provider') {
            // Sync customer from Shopify to provider
            $shopifyCustomer = $payload['shopify_customer'] ?? null;
            if ($shopifyCustomer) {
                $customerSyncService->syncToProvider($shopifyCustomer, $providerName);
            }
        } elseif ($operation === 'create') {
            // Create customer in provider
            $customerData = $payload['customer_data'] ?? null;
            if ($customerData && $provider->checkCapability('customer_create')) {
                $provider->createCustomer($customerData);
            }
        } elseif ($operation === 'update') {
            // Update customer in provider
            $customerId = $payload['customer_id'] ?? null;
            $customerData = $payload['customer_data'] ?? null;
            if ($customerId && $customerData && $provider->checkCapability('customer_update')) {
                $provider->updateCustomer($customerId, $customerData);
            }
        }
    }

    /**
     * Retry order operation
     */
    private function retryOrderOperation(string $operation, array $payload): void
    {
        if ($operation === 'process') {
            // Process order from Shopify to ERP
            $orderData = $payload['order_data'] ?? null;
            if ($orderData) {
                $orderProcessingService = new OrderProcessingService();
                $orderProcessingService->processOrder($orderData);
            }
        } elseif ($operation === 'create_transaction') {
            // Create transaction in ERP
            $providerName = $payload['provider'] ?? 'wws';
            $provider = ProviderFactory::createErpProvider($providerName);
            $transactionData = $payload['transaction_data'] ?? null;
            
            if ($transactionData && $provider->checkCapability('order_create')) {
                $provider->createTransaction($transactionData);
            }
        } elseif ($operation === 'update_status') {
            // Update order status in Shopify
            $orderId = $payload['order_id'] ?? null;
            $status = $payload['status'] ?? null;
            if ($orderId && $status) {
                $ecommerceProvider = ProviderFactory::createEcommerceProvider('shopify');
                // Note: Order status update would require OrderService updateOrder method
                // For now, log the status update
                LogHelper::info('Order status update requested', [
                    'order_id' => $orderId,
                    'status' => $status,
                ]);
            }
        }
    }

    /**
     * Handle retry failure
     */
    private function handleRetry(array $error, \Exception $e): void
    {
        $retryCount = $error['retry_count'] + 1;
        
        LogHelper::warning('Retry failed for error queue item', [
            'error_id' => $error['id'],
            'entity_type' => $error['entity_type'],
            'entity_id' => $error['entity_id'],
            'retry_count' => $retryCount,
            'max_retries' => $error['max_retries'],
            'error' => $e->getMessage(),
        ]);
        $maxRetries = $error['max_retries'] ?? 3;

        if ($retryCount < $maxRetries) {
            // Schedule next retry with exponential backoff
            $delay = pow(2, $retryCount) * 5; // 5, 10, 20 minutes
            $nextRetry = date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));
            
            $stmt = $this->db->prepare("
                UPDATE error_queue 
                SET status = 'pending',
                    retry_count = ?,
                    next_retry_at = ?,
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$retryCount, $nextRetry, $e->getMessage(), $error['id']]);
        } else {
            // Mark as failed (dead letter)
            $stmt = $this->db->prepare("
                UPDATE error_queue 
                SET status = 'failed',
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $error['id']]);
        }
    }
}

