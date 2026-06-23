<?php

namespace App\Core\Scheduler;

use App\Database\Database;
use App\Core\Factory\ProviderFactory;
use App\Core\Services\ProductSyncService;
use App\Core\Services\InventorySyncService;
use App\Core\Services\PriceSyncService;
use App\Core\Services\CustomerSyncService;
use App\Core\Services\OrderProcessingService;
use App\Database\Repository\ProductMappingRepository;
use App\Adapters\WwsRestService\ProductAdapter as WwsAdapter;
use App\Core\Models\Product;
use App\Utils\LogHelper;
use PDO;

/**
 * Scheduler Service
 * Manages scheduled jobs and execution
 */
class SchedulerService
{
    private $db;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->config = require BASE_PATH . '/config/scheduler.php';
    }

    /**
     * Schedule a recurring job
     * @param string $jobName
     * @param string $interval Interval string (e.g., '5m', '1h', '12h', 'daily')
     * @param callable $callback
     * @return int Job ID
     */
    public function scheduleJob(string $jobName, string $interval, callable $callback = null): int
    {
        $scheduledAt = $this->calculateNextRun($interval);
        
        $stmt = $this->db->prepare("
            INSERT INTO job_queue 
            (job_name, job_type, payload, status, scheduled_at)
            VALUES (?, 'recurring', ?, 'pending', ?)
        ");
        
        // Don't serialize callback - jobs are executed by job_name, not callback
        $payload = json_encode([
            'interval' => $interval,
        ]);
        
        $stmt->execute([$jobName, $payload, $scheduledAt]);
        return $this->db->lastInsertId();
    }

    /**
     * Add one-time job
     * @param string $jobName
     * @param \DateTime|string $executeAt
     * @param callable $callback
     * @return int Job ID
     */
    public function addOneTimeJob(string $jobName, $executeAt, callable $callback = null): int
    {
        if (is_string($executeAt)) {
            $executeAt = new \DateTime($executeAt);
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO job_queue 
            (job_name, job_type, payload, status, scheduled_at)
            VALUES (?, 'onetime', ?, 'pending', ?)
        ");
        
        // Don't serialize callback - jobs are executed by job_name
        $payload = json_encode([]);
        
        $stmt->execute([$jobName, $payload, $executeAt->format('Y-m-d H:i:s')]);
        return $this->db->lastInsertId();
    }

    /**
     * Run all due jobs
     * @return array Statistics
     */
    public function runScheduledJobs(): array
    {
        $stats = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
        ];

        // Get pending jobs that are due
        $stmt = $this->db->prepare("
            SELECT * FROM job_queue 
            WHERE status = 'pending' 
            AND scheduled_at <= NOW()
            ORDER BY scheduled_at ASC
            LIMIT 50
        ");
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($jobs as $job) {
            try {
                $this->executeJob($job);
                $stats['succeeded']++;
            } catch (\Exception $e) {
                LogHelper::error('Job execution failed', [
                    'job_name' => $job['job_name'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $this->handleJobFailure($job, $e);
                $stats['failed']++;
            }
            $stats['processed']++;
        }

        return $stats;
    }

    /**
     * Execute a job
     * @param array $job
     */
    private function executeJob(array $job): void
    {
        // Mark as running
        $stmt = $this->db->prepare("
            UPDATE job_queue 
            SET status = 'running', started_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$job['id']]);

        try {
            $payload = json_decode($job['payload'], true);
            
            // Handle different job types
            if ($job['job_name'] === 'product_sync') {
                $this->executeProductSync($payload);
            } elseif ($job['job_name'] === 'inventory_sync') {
                $this->executeInventorySync($payload);
            } elseif ($job['job_name'] === 'price_sync') {
                $this->executePriceSync($payload);
            } elseif ($job['job_name'] === 'customer_sync') {
                $this->executeCustomerSync($payload);
            } elseif ($job['job_name'] === 'order_status_sync') {
                $this->executeOrderStatusSync($payload);
            } else {
                // Unknown job type - log warning
                LogHelper::warning('Unknown job type in scheduler', [
                    'job_name' => $job['job_name'] ?? 'unknown',
                    'job_type' => $job['job_type'] ?? 'unknown',
                ]);
            }

            // Mark as completed
            $nextRun = null;
            if ($job['job_type'] === 'recurring' && isset($payload['interval'])) {
                $nextRun = $this->calculateNextRun($payload['interval']);
            }

            $stmt = $this->db->prepare("
                UPDATE job_queue 
                SET status = 'completed', 
                    completed_at = NOW(),
                    scheduled_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$nextRun, $job['id']]);
        } catch (\Exception $e) {
            LogHelper::error('Failed to update job next run time', [
                'job_id' => $job['id'] ?? null,
                'job_name' => $job['name'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Execute product sync job
     */
    private function executeProductSync(array $payload): void
    {
        $jobConfig = $this->config['jobs']['product_sync'];
        if (!$jobConfig['enabled']) {
            return;
        }

        $provider = ProviderFactory::createErpProvider($jobConfig['provider']);
        $syncService = new ProductSyncService($provider);
        $syncService->syncAllProducts($jobConfig['limit']);
    }

    /**
     * Execute inventory sync job
     */
    private function executeInventorySync(array $payload): void
    {
        $jobConfig = $this->config['jobs']['inventory_sync'];
        if (!$jobConfig['enabled']) {
            return;
        }

        $provider = ProviderFactory::createErpProvider($jobConfig['provider']);
        $inventorySyncService = new InventorySyncService($provider);
        $productMappingRepository = new ProductMappingRepository();
        $wwsAdapter = new WwsAdapter();
        
        // Get all synced products from mapping
        $mappings = $productMappingRepository->getAllMappings();
        
        $synced = 0;
        $errors = 0;
        
        foreach ($mappings as $mapping) {
            try {
                // Get product from ERP
                $erpProduct = $provider->getProduct($mapping['wws_product_id']);
                if (!$erpProduct) {
                    continue;
                }
                
                // Convert to core model
                $coreProduct = $wwsAdapter->toCoreModel($erpProduct);
                
                // Get Shopify product ID
                if (!$mapping['shopify_product_id']) {
                    continue;
                }
                
                $coreProduct->id = $mapping['shopify_product_id'];
                
                // Sync inventory
                $providerInventories = [
                    $jobConfig['provider'] => $coreProduct->inventoryQty,
                ];
                
                $inventorySyncService->syncInventory($coreProduct, $providerInventories);
                $synced++;
                } catch (\Exception $e) {
                $errors++;
                LogHelper::error('Failed to sync inventory for product', [
                    'wws_product_id' => $mapping['wws_product_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        LogHelper::info('Inventory sync completed', [
            'synced' => $synced,
            'errors' => $errors,
        ]);
    }

    /**
     * Execute price sync job
     */
    private function executePriceSync(array $payload): void
    {
        $jobConfig = $this->config['jobs']['price_sync'];
        if (!$jobConfig['enabled']) {
            return;
        }

        $provider = ProviderFactory::createErpProvider($jobConfig['provider']);
        $priceSyncService = new PriceSyncService($provider);
        $productMappingRepository = new ProductMappingRepository();
        $wwsAdapter = new WwsAdapter();
        
        // Get all synced products from mapping
        $mappings = $productMappingRepository->getAllMappings();
        
        $synced = 0;
        $errors = 0;
        
        foreach ($mappings as $mapping) {
            try {
                // Get product from ERP
                $erpProduct = $provider->getProduct($mapping['wws_product_id']);
                if (!$erpProduct) {
                    continue;
                }
                
                // Convert to core model
                $coreProduct = $wwsAdapter->toCoreModel($erpProduct);
                
                // Get Shopify product ID
                if (!$mapping['shopify_product_id']) {
                    continue;
                }
                
                $coreProduct->id = $mapping['shopify_product_id'];
                
                // Sync price
                $providerPrices = [
                    $jobConfig['provider'] => $coreProduct->price,
                ];
                
                $priceSyncService->syncPrice($coreProduct, $providerPrices);
                $synced++;
            } catch (\Exception $e) {
                $errors++;
                LogHelper::error('Failed to sync price for product', [
                    'wws_product_id' => $mapping['wws_product_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        LogHelper::info('Price sync completed', [
            'synced' => $synced,
            'errors' => $errors,
        ]);
    }

    /**
     * Execute customer sync job
     */
    private function executeCustomerSync(array $payload): void
    {
        $jobConfig = $this->config['jobs']['customer_sync'];
        if (!$jobConfig['enabled']) {
            return;
        }

        $provider = ProviderFactory::createErpProvider($jobConfig['provider']);
        $syncService = new CustomerSyncService($provider);
        $syncService->syncFromProvider($jobConfig['provider']);
    }

    /**
     * Execute order processing (process pending orders from queue)
     */
    private function executeOrderProcessing(array $payload): void
    {
        $jobConfig = $this->config['jobs']['order_processing'];
        if (!$jobConfig['enabled']) {
            return;
        }
        
        $limit = $payload['limit'] ?? $jobConfig['limit'] ?? 10;
        
        try {
            $orderQueueRepository = new \App\Database\Repository\OrderQueueRepository();
            $orderProcessingService = new OrderProcessingService();
            
            $pendingOrders = $orderQueueRepository->getPendingOrders($limit);
            
            if (empty($pendingOrders)) {
                LogHelper::debug('No pending orders to process');
                return;
            }
            
            LogHelper::info('Processing pending orders', [
                'count' => count($pendingOrders),
            ]);
            
            $processed = 0;
            $failed = 0;
            
            foreach ($pendingOrders as $order) {
                try {
                    $orderData = json_decode($order['order_data'], true);
                    if (!$orderData) {
                        LogHelper::error('Invalid order data in queue', [
                            'queue_id' => $order['id'] ?? null,
                        ]);
                        $failed++;
                        continue;
                    }
                    
                    $result = $orderProcessingService->processOrder($orderData);
                    $processed++;
                    
                    LogHelper::info('Order processed successfully', [
                        'shopify_order_id' => $order['shopify_order_id'] ?? null,
                        'shopify_order_number' => $order['shopify_order_number'] ?? null,
                        'transaction_id' => !empty($result['results']) ? reset($result['results'])['transaction_id'] : null,
                    ]);
                } catch (\Exception $e) {
                    $failed++;
                    LogHelper::error('Order processing failed in scheduler', [
                        'order_id' => $order['shopify_order_id'] ?? null,
                        'order_number' => $order['shopify_order_number'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            LogHelper::info('Order processing completed', [
                'processed' => $processed,
                'failed' => $failed,
            ]);
        } catch (\Exception $e) {
            LogHelper::error('Order processing scheduler job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Execute order status sync job
     * Syncs order status from ERP providers back to Shopify
     */
    private function executeOrderStatusSync(array $payload): void
    {
        $jobConfig = $this->config['jobs']['order_status_sync'];
        if (!$jobConfig['enabled']) {
            return;
        }

        $providers = $jobConfig['providers'] ?? ['wws'];
        $orderQueueRepository = new \App\Database\Repository\OrderQueueRepository();
        
        // Get completed orders that need status sync
        $completedOrders = $orderQueueRepository->getCompletedOrders();
        
        $synced = 0;
        $errors = 0;
        
        foreach ($completedOrders as $order) {
            try {
                $transactionId = $order['wws_transaction_id'] ?? null;
                if (!$transactionId) {
                    continue;
                }
                
                // Get transaction status from each provider
                foreach ($providers as $providerName) {
                    try {
                        $provider = ProviderFactory::createErpProvider($providerName);
                        
                        if (!$provider->checkCapability('order_read')) {
                            continue;
                        }
                        
                        $transaction = $provider->getTransaction($transactionId);
                        if (!$transaction) {
                            continue;
                        }
                        
                        // Map transaction status to Shopify order status
                        $shopifyStatus = $this->mapTransactionStatusToShopify($transaction);
                        
                        // Update Shopify order status (if needed)
                        // Note: This requires Shopify OrderService updateOrder method
                        // For now, just log the status
                        LogHelper::debug('Order status check', [
                            'shopify_order_id' => $order['shopify_order_id'],
                            'status' => $shopifyStatus,
                        ]);
                        
                        $synced++;
                        break; // Only sync from first provider that has the transaction
                    } catch (\Exception $e) {
                        LogHelper::warning('Failed to get transaction status from provider', [
                            'provider' => $providerName,
                            'order_id' => $order['shopify_order_id'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                LogHelper::error('Failed to sync order status', [
                    'shopify_order_id' => $order['shopify_order_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        LogHelper::info('Order status sync completed', [
            'synced' => $synced,
            'errors' => $errors,
        ]);
    }
    
    /**
     * Map ERP transaction status to Shopify order status
     * @param array $transaction ERP transaction data
     * @return string Shopify order status
     */
    private function mapTransactionStatusToShopify(array $transaction): string
    {
        // Default mapping - should be configurable
        $status = $transaction['status'] ?? $transaction['state'] ?? 'pending';
        
        $statusMap = [
            'pending' => 'pending',
            'processing' => 'processing',
            'completed' => 'fulfilled',
            'fulfilled' => 'fulfilled',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
        ];
        
        return $statusMap[strtolower($status)] ?? 'pending';
    }

    /**
     * Handle job failure
     */
    private function handleJobFailure(array $job, \Exception $e): void
    {
        $retryCount = $job['retry_count'] + 1;
        $maxRetries = $job['max_retries'] ?? 3;

        if ($retryCount < $maxRetries) {
            // Reschedule for retry
            $nextRetry = date('Y-m-d H:i:s', strtotime('+' . ($retryCount * 5) . ' minutes'));
            
            $stmt = $this->db->prepare("
                UPDATE job_queue 
                SET status = 'pending',
                    retry_count = ?,
                    scheduled_at = ?,
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$retryCount, $nextRetry, $e->getMessage(), $job['id']]);
        } else {
            // Mark as failed
            $stmt = $this->db->prepare("
                UPDATE job_queue 
                SET status = 'failed',
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([$e->getMessage(), $job['id']]);
        }
    }

    /**
     * Calculate next run time from interval
     * @param string $interval
     * @return string DateTime string
     */
    private function calculateNextRun(string $interval): string
    {
        $now = new \DateTime();
        
        // Parse interval (e.g., '5m', '1h', '12h', 'daily')
        if (preg_match('/^(\d+)([mhd])$/', $interval, $matches)) {
            $value = (int)$matches[1];
            $unit = $matches[2];
            
            switch ($unit) {
                case 'm':
                    $now->modify("+{$value} minutes");
                    break;
                case 'h':
                    $now->modify("+{$value} hours");
                    break;
                case 'd':
                    $now->modify("+{$value} days");
                    break;
            }
        } elseif ($interval === 'daily') {
            $now->modify('+1 day');
            $now->setTime(0, 0, 0);
        } else {
            // Default: 1 hour
            $now->modify('+1 hour');
        }
        
        return $now->format('Y-m-d H:i:s');
    }

    /**
     * Initialize default jobs from config
     */
    public function initializeJobs(): void
    {
        foreach ($this->config['jobs'] as $jobName => $jobConfig) {
            if (!$jobConfig['enabled']) {
                continue;
            }

            // Check if job already exists
            $stmt = $this->db->prepare("
                SELECT id FROM job_queue 
                WHERE job_name = ? AND job_type = 'recurring'
                LIMIT 1
            ");
            $stmt->execute([$jobName]);
            if ($stmt->fetch()) {
                continue; // Job already exists
            }

            // Schedule job (callback not needed - jobs are executed by job_name)
            $this->scheduleJob($jobName, $jobConfig['interval']);
        }
    }
}

