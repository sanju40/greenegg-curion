<?php

namespace App\Api\Shopify;

/**
 * Shopify Inventory Service
 * Handles inventory operations using Inventory API
 * Required for stores with inventory locations
 */
class InventoryService
{
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get inventory item ID from variant
     * @param mixed $variantId
     * @return string|null Inventory item ID
     */
    public function getInventoryItemId($variantId)
    {
        // Get variant details to extract inventory_item_id
        $productService = new ProductService($this->client);
        
        // We need to get the product first to find the variant
        // This is a limitation - we need product ID
        // For now, we'll need to pass inventory_item_id from the variant data
        return null;
    }

    /**
     * Get inventory levels for an inventory item
     * @param string $inventoryItemId
     * @return array
     */
    public function getInventoryLevels($inventoryItemId)
    {
        $result = $this->client->get('inventory_levels.json', [
            'inventory_item_ids' => $inventoryItemId,
        ]);
        
        return $result['inventory_levels'] ?? [];
    }

    /**
     * Set inventory quantity for an inventory item at a location
     * @param string|int $inventoryItemId
     * @param string|int $locationId
     * @param int $quantity
     * @return array|null
     */
    public function setInventoryLevel($inventoryItemId, $locationId, $quantity)
    {
        $result = $this->client->post('inventory_levels/set.json', [
            'inventory_item_id' => $inventoryItemId,
            'location_id' => $locationId,
            'available' => (int)$quantity,
        ]);
        
        return $result['inventory_level'] ?? null;
    }

    /**
     * Adjust inventory quantity (add/subtract)
     * @param string $inventoryItemId
     * @param string $locationId
     * @param int $quantityAdjustment Positive to add, negative to subtract
     * @return array|null
     */
    public function adjustInventoryLevel($inventoryItemId, $locationId, $quantityAdjustment)
    {
        $result = $this->client->post('inventory_levels/adjust.json', [
            'inventory_item_id' => $inventoryItemId,
            'location_id' => $locationId,
            'quantity_adjustment' => (int)$quantityAdjustment,
        ]);
        
        return $result['inventory_level'] ?? null;
    }

    /**
     * Get all locations
     * @return array
     */
    public function getLocations()
    {
        $result = $this->client->get('locations.json');
        return $result['locations'] ?? [];
    }
}

