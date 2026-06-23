<?php

namespace App\Database\Repository;

use App\Database\Database;
use PDO;

/**
 * Product Mapping Repository
 * Handles product mapping database operations
 */
class ProductMappingRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Find mapping by WwsRestService product ID
     */
    public function findByWwsProductId($wwsProductId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM product_mappings 
            WHERE wws_product_id = ?
        ");
        $stmt->execute([$wwsProductId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Find mapping by SKU
     */
    public function findBySku($sku)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM product_mappings 
            WHERE wws_product_sku = ?
        ");
        $stmt->execute([$sku]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Find mapping by Shopify product ID
     */
    public function findByShopifyProductId($shopifyProductId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM product_mappings 
            WHERE shopify_product_id = ?
        ");
        $stmt->execute([$shopifyProductId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create or update mapping
     * Prevents duplicate entries by checking both WWS product ID and SKU
     */
    public function save($wwsProductId, $wwsSku, $shopifyProductId = null, $shopifyVariantId = null, $syncStatus = 'synced')
    {
        // First check by WWS product ID
        $existing = $this->findByWwsProductId($wwsProductId);

        if ($existing) {
            // Update existing mapping
            $stmt = $this->db->prepare("
                UPDATE product_mappings 
                SET wws_product_sku = ?,
                    shopify_product_id = ?,
                    shopify_variant_id = ?,
                    sync_status = ?,
                    last_synced_at = NOW(),
                    updated_at = NOW()
                WHERE wws_product_id = ?
            ");
            $stmt->execute([
                $wwsSku,
                $shopifyProductId,
                $shopifyVariantId,
                $syncStatus,
                $wwsProductId
            ]);
            return $existing['id'];
        }
        
        // Check if SKU already exists (prevent duplicates)
        if (!empty($wwsSku)) {
            $existingBySku = $this->findBySku($wwsSku);
            if ($existingBySku) {
                // Update existing mapping with same SKU
                $stmt = $this->db->prepare("
                    UPDATE product_mappings 
                    SET wws_product_id = ?,
                        shopify_product_id = ?,
                        shopify_variant_id = ?,
                        sync_status = ?,
                        last_synced_at = NOW(),
                        updated_at = NOW()
                    WHERE wws_product_sku = ?
                ");
                $stmt->execute([
                    $wwsProductId,
                    $shopifyProductId,
                    $shopifyVariantId,
                    $syncStatus,
                    $wwsSku
                ]);
                return $existingBySku['id'];
            }
        }
        
        // Check if Shopify product ID already exists (prevent duplicates)
        if (!empty($shopifyProductId)) {
            $existingByShopify = $this->findByShopifyProductId($shopifyProductId);
            if ($existingByShopify) {
                // Update existing mapping with same Shopify product ID
                $stmt = $this->db->prepare("
                    UPDATE product_mappings 
                    SET wws_product_id = ?,
                        wws_product_sku = ?,
                        shopify_variant_id = ?,
                        sync_status = ?,
                        last_synced_at = NOW(),
                        updated_at = NOW()
                    WHERE shopify_product_id = ?
                ");
                $stmt->execute([
                    $wwsProductId,
                    $wwsSku,
                    $shopifyVariantId,
                    $syncStatus,
                    $shopifyProductId
                ]);
                return $existingByShopify['id'];
            }
        }
        
        // Insert new mapping
        $stmt = $this->db->prepare("
            INSERT INTO product_mappings 
            (wws_product_id, wws_product_sku, shopify_product_id, shopify_variant_id, sync_status, last_synced_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $wwsProductId,
            $wwsSku,
            $shopifyProductId,
            $shopifyVariantId,
            $syncStatus
        ]);
        return $this->db->lastInsertId();
    }

    /**
     * Find mapping by Shopify variant ID
     */
    public function findByShopifyVariantId($shopifyVariantId)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM product_mappings 
            WHERE shopify_variant_id = ?
        ");
        $stmt->execute([$shopifyVariantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Save a mapping imported from Shopify when the WWS product ID is not yet known.
     *
     * Priority:
     *   1. If SKU already in product_mappings → update Shopify IDs, preserve real wws_product_id.
     *   2. If Shopify variant ID already in product_mappings → update SKU + product ID.
     *   3. Insert new row using placeholder wws_product_id = 'shopify:{variant_id}'.
     *
     * The placeholder is overwritten automatically by the normal save() call the first
     * time a real WWS product sync runs for the same SKU.
     *
     * @return array ['action' => 'created'|'updated', 'id' => int]
     */
    public function saveFromShopify(string $sku, string $shopifyProductId, string $shopifyVariantId): array
    {
        // ── 1. Existing row by SKU ────────────────────────────────────────────
        $existingBySku = $this->findBySku($sku);
        if ($existingBySku) {
            $stmt = $this->db->prepare("
                UPDATE product_mappings
                SET shopify_product_id = ?,
                    shopify_variant_id  = ?,
                    last_synced_at      = NOW(),
                    updated_at          = NOW()
                WHERE wws_product_sku = ?
            ");
            $stmt->execute([$shopifyProductId, $shopifyVariantId, $sku]);
            return ['action' => 'updated', 'id' => (int)$existingBySku['id']];
        }

        // ── 2. Existing row by Shopify variant ID ─────────────────────────────
        $existingByVariant = $this->findByShopifyVariantId($shopifyVariantId);
        if ($existingByVariant) {
            $stmt = $this->db->prepare("
                UPDATE product_mappings
                SET wws_product_sku    = ?,
                    shopify_product_id = ?,
                    last_synced_at     = NOW(),
                    updated_at         = NOW()
                WHERE shopify_variant_id = ?
            ");
            $stmt->execute([$sku, $shopifyProductId, $shopifyVariantId]);
            return ['action' => 'updated', 'id' => (int)$existingByVariant['id']];
        }

        // ── 3. New row with placeholder wws_product_id ────────────────────────
        // Format: 'shopify:{variant_id}' — clearly not a real WWS numeric ID.
        // Overwritten by save() when the real WWS product sync runs for the same SKU.
        $placeholder = 'shopify:' . $shopifyVariantId;
        $stmt = $this->db->prepare("
            INSERT INTO product_mappings
                (wws_product_id, wws_product_sku, shopify_product_id, shopify_variant_id, sync_status, last_synced_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$placeholder, $sku, $shopifyProductId, $shopifyVariantId]);
        return ['action' => 'created', 'id' => (int)$this->db->lastInsertId()];
    }

    /**
     * Delete mapping by Shopify product ID (used to purge stale entries)
     */
    public function deleteByShopifyProductId($shopifyProductId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM product_mappings WHERE shopify_product_id = ?'
        );
        return $stmt->execute([$shopifyProductId]);
    }

    /**
     * Persist the Shopify tag string for a mapping row.
     *
     * Stores the raw comma-separated tag string exactly as Shopify returns it
     * (no JSON encoding) so consumers can splice it back into update payloads
     * without re-formatting. shopify_tags_updated_at is bumped to NOW() so a
     * future products/update webhook can rely on freshness checks.
     *
     * @param string      $shopifyProductId  Numeric Shopify product ID
     * @param string|null $tags              Comma-separated tag list, or null/empty to clear
     * @return bool
     */
    public function updateShopifyTags(string $shopifyProductId, ?string $tags): bool
    {
        $stmt = $this->db->prepare("
            UPDATE product_mappings
            SET shopify_tags            = ?,
                shopify_tags_updated_at = NOW(),
                updated_at              = NOW()
            WHERE shopify_product_id = ?
        ");
        return $stmt->execute([$tags, $shopifyProductId]);
    }

    /**
     * Read the stored Shopify tag list for a product as an array.
     *
     * Returns [] when the row is missing, the column is null, or the string is
     * empty. Trims whitespace and drops empty entries so callers can union the
     * result directly without further sanitisation.
     *
     * @param string $shopifyProductId Numeric Shopify product ID
     * @return array<int,string>
     */
    public function getShopifyTags(string $shopifyProductId): array
    {
        $stmt = $this->db->prepare("
            SELECT shopify_tags FROM product_mappings
            WHERE shopify_product_id = ?
        ");
        $stmt->execute([$shopifyProductId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['shopify_tags'])) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $row['shopify_tags']))));
    }

    /**
     * Update sync status
     */
    public function updateSyncStatus($wwsProductId, $status)
    {
        $stmt = $this->db->prepare("
            UPDATE product_mappings 
            SET sync_status = ?,
                updated_at = NOW()
            WHERE wws_product_id = ?
        ");
        return $stmt->execute([$status, $wwsProductId]);
    }

    /**
     * Get all mappings
     * @param int|null $limit Optional limit
     * @return array
     */
    public function getAllMappings($limit = null)
    {
        $sql = "
            SELECT * FROM product_mappings 
            WHERE sync_status = 'synced'
            ORDER BY last_synced_at DESC
        ";
        
        if ($limit !== null) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

