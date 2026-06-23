<?php

namespace App\Api\WwsRestService;

/**
 * Product Service
 * Handles product-related API operations
 */
class ProductService
{
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get single product by ID
     */
    public function getProduct($productId)
    {
        $databaseId = $this->client->getDatabaseId();
        $result = $this->client->get("product/{$databaseId}/{$productId}");
        
        // Handle array response - WWS sometimes returns [0 => product]
        if (is_array($result) && isset($result[0]) && count($result) === 1) {
            return $result[0];
        }
        
        return $result;
    }

    /**
     * Search products
     */
    public function searchProducts($searchString, $offset = 0, $limit = 0)
    {
        $databaseId = $this->client->getDatabaseId();
        $endpoint = "productSearch/{$databaseId}/{$searchString}";
        
        if ($offset > 0 || $limit > 0) {
            $endpoint .= "/{$offset}/{$limit}";
        }
        
        return $this->client->get($endpoint);
    }

    /**
     * Get multiple products by IDs
     */
    public function getProducts(array $productIds)
    {
        $databaseId = $this->client->getDatabaseId();
        $ids = implode(',', $productIds);
        return $this->client->get("products/{$databaseId}/{$ids}");
    }

    /**
     * Get product by SKU
     */
    public function getProductBySku($sku)
    {
        $results = $this->searchProducts("*{$sku}*", 0, 1);
        if (!empty($results) && is_array($results)) {
            foreach ($results as $result) {
                if (isset($result['sku']) && $result['sku'] === $sku) {
                    return $result;
                }
            }
        }
        return null;
    }
}

