<?php

namespace App\Database\Repository;

use App\Database\Database;
use PDO;

/**
 * Order Queue Repository
 * Handles order queue database operations
 */
class OrderQueueRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Add order to queue
     */
    public function addOrder($shopifyOrderId, $shopifyOrderNumber, $orderData)
    {
        $stmt = $this->db->prepare("
            INSERT INTO order_queue 
            (shopify_order_id, shopify_order_number, order_data, status)
            VALUES (?, ?, ?, 'pending')
            ON DUPLICATE KEY UPDATE
                order_data = VALUES(order_data),
                status = 'pending',
                updated_at = NOW()
        ");
        
        $orderDataJson = json_encode($orderData, JSON_UNESCAPED_UNICODE);
        return $stmt->execute([$shopifyOrderId, $shopifyOrderNumber, $orderDataJson]);
    }

    /**
     * Get pending orders (including retries that are due)
     */
    public function getPendingOrders($limit = 10)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM order_queue 
            WHERE status = 'pending'
            ORDER BY created_at ASC, retry_count ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find order by Shopify order ID
     */
    public function findByShopifyOrderId($shopifyOrderId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM order_queue 
            WHERE shopify_order_id = ?
        ");
        $stmt->execute([$shopifyOrderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update order status by ID
     */
    public function updateStatus($id, $status, $wwsTransactionId = null, $errorMessage = null)
    {
        $stmt = $this->db->prepare("
            UPDATE order_queue 
            SET status = ?,
                wws_transaction_id = ?,
                error_message = ?,
                processed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE processed_at END,
                updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$status, $wwsTransactionId, $errorMessage, $status, $id]);
    }

    /**
     * Update order status by Shopify order ID
     */
    public function updateStatusByShopifyOrderId($shopifyOrderId, $status, $wwsTransactionId = null, $errorMessage = null)
    {
        $stmt = $this->db->prepare("
            UPDATE order_queue 
            SET status = ?,
                wws_transaction_id = ?,
                error_message = ?,
                processed_at = CASE WHEN ? = 'completed' THEN NOW() ELSE processed_at END,
                updated_at = NOW()
            WHERE shopify_order_id = ?
        ");
        return $stmt->execute([$status, $wwsTransactionId, $errorMessage, $status, $shopifyOrderId]);
    }

    /**
     * Update provider payload for an order
     * @param string $shopifyOrderId
     * @param array $providerPayloads Payloads keyed by provider ID (e.g., ['wws' => {...}, 'sap' => {...}])
     * @return bool
     */
    public function updateProviderPayload($shopifyOrderId, array $providerPayloads)
    {
        $payloadJson = json_encode($providerPayloads, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        $stmt = $this->db->prepare("
            UPDATE order_queue 
            SET provider_payload = ?,
                updated_at = NOW()
            WHERE shopify_order_id = ?
        ");
        
        return $stmt->execute([$payloadJson, $shopifyOrderId]);
    }

    /**
     * Save provider payload only when the order already exists in order_queue (preview CLI orders may not).
     */
    public function saveProviderPayloadIfQueued($shopifyOrderId, array $providerPayloads): bool
    {
        if (!$this->findByShopifyOrderId($shopifyOrderId)) {
            return false;
        }

        return $this->updateProviderPayload($shopifyOrderId, $providerPayloads);
    }

    /**
     * Merge provider payload for an order (adds/updates payload for a specific provider)
     * @param string $shopifyOrderId
     * @param string $providerId
     * @param array $payload
     * @return bool
     */
    public function mergeProviderPayload($shopifyOrderId, string $providerId, array $payload)
    {
        // Get existing payloads
        $order = $this->findByShopifyOrderId($shopifyOrderId);
        $existingPayloads = [];
        
        if ($order && !empty($order['provider_payload'])) {
            $existingPayloads = json_decode($order['provider_payload'], true) ?? [];
        }
        
        // Merge new payload
        $existingPayloads[$providerId] = $payload;
        
        return $this->updateProviderPayload($shopifyOrderId, $existingPayloads);
    }

    /**
     * Increment retry count and reschedule for retry
     * @param int|string $id Order queue ID or Shopify order ID
     * @param int $retryDelayMinutes Delay before next retry (exponential backoff)
     * @param bool $byShopifyOrderId If true, $id is Shopify order ID, otherwise queue ID
     * @return bool
     */
    public function incrementRetryCount($id, int $retryDelayMinutes = 5, bool $byShopifyOrderId = false)
    {
        $nextRetryAt = date('Y-m-d H:i:s', strtotime("+{$retryDelayMinutes} minutes"));
        
        if ($byShopifyOrderId) {
            $stmt = $this->db->prepare("
                UPDATE order_queue 
                SET retry_count = retry_count + 1,
                    status = 'pending',
                    updated_at = NOW()
                WHERE shopify_order_id = ?
            ");
        } else {
            $stmt = $this->db->prepare("
                UPDATE order_queue 
                SET retry_count = retry_count + 1,
                    status = 'pending',
                    updated_at = NOW()
                WHERE id = ?
            ");
        }
        
        return $stmt->execute([$id]);
    }

    /**
     * Get order by ID (queue ID or Shopify order ID)
     * @param int|string $id
     * @param bool $byShopifyOrderId
     * @return array|null
     */
    public function getOrder($id, bool $byShopifyOrderId = false)
    {
        if ($byShopifyOrderId) {
            return $this->findByShopifyOrderId($id);
        }
        
        $stmt = $this->db->prepare("
            SELECT * FROM order_queue 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get failed orders
     */
    public function getFailedOrders($limit = 10)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM order_queue 
            WHERE status = 'failed'
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get completed orders
     * @param int|null $limit Optional limit
     * @return array
     */
    public function getCompletedOrders($limit = null)
    {
        $sql = "
            SELECT * FROM order_queue 
            WHERE status = 'completed'
            AND wws_transaction_id IS NOT NULL
            ORDER BY updated_at DESC
        ";
        
        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

