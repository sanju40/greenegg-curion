<?php

namespace App\Database\Repository;

use App\Database\Database;
use PDO;

/**
 * Customer Mapping Repository
 * Handles customer mapping database operations
 */
class CustomerMappingRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Find mapping by Shopify customer ID
     */
    public function findByShopifyCustomerId($shopifyCustomerId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM customer_mappings 
            WHERE shopify_customer_id = ?
        ");
        $stmt->execute([$shopifyCustomerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Find mapping by WwsRestService customer ID
     */
    public function findByWwsCustomerId($wwsCustomerId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM customer_mappings 
            WHERE wws_customer_id = ?
        ");
        $stmt->execute([$wwsCustomerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create or update mapping
     */
    public function save($shopifyCustomerId, $wwsCustomerId = null, $customerData = null)
    {
        $existing = $this->findByShopifyCustomerId($shopifyCustomerId);

        $customerDataJson = $customerData ? json_encode($customerData, JSON_UNESCAPED_UNICODE) : null;

        if ($existing) {
            // Update
            $stmt = $this->db->prepare("
                UPDATE customer_mappings 
                SET wws_customer_id = ?,
                    customer_data = ?,
                    last_synced_at = NOW(),
                    updated_at = NOW()
                WHERE shopify_customer_id = ?
            ");
            $stmt->execute([
                $wwsCustomerId,
                $customerDataJson,
                $shopifyCustomerId
            ]);
            return $existing['id'];
        } else {
            // Insert
            $stmt = $this->db->prepare("
                INSERT INTO customer_mappings 
                (shopify_customer_id, wws_customer_id, customer_data, last_synced_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([
                $shopifyCustomerId,
                $wwsCustomerId,
                $customerDataJson
            ]);
            return $this->db->lastInsertId();
        }
    }
}

