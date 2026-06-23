<?php

namespace App\Api\Shopify;

/**
 * Shopify Product Service
 * Handles Shopify product operations
 */
class ProductService
{
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get product by ID
     * Returns null if the product does not exist (404), re-throws any other error.
     */
    public function getProduct($productId)
    {
        try {
            $result = $this->client->get("products/{$productId}.json");
            return $result['product'] ?? null;
        } catch (\App\Exceptions\ShopifyException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Get product by SKU using GraphQL.
     *
     * REST variants.json without product_id is deprecated in Shopify API 2024-01+
     * and always returns an empty array, making SKU search impossible via REST.
     * GraphQL productVariants(query:"sku:X") is the only reliable approach.
     *
     * The query uses exact-phrase syntax (sku:"VALUE") to avoid prefix/substring
     * false positives, e.g. searching for "131" without quotes would also match
     * "131348", "1310", etc. and the real match might land outside first:N.
     */
    public function getProductBySku($sku)
    {
        $trimmedSku = trim($sku);

        // Escape any double-quotes inside the SKU itself before wrapping in quotes.
        $escapedSku = str_replace('"', '\\"', $trimmedSku);

        $query = <<<'GQL'
        query GetVariantBySku($query: String!) {
            productVariants(first: 25, query: $query) {
                edges {
                    node {
                        sku
                        legacyResourceId
                        product {
                            legacyResourceId
                        }
                    }
                }
            }
        }
        GQL;

        try {
            // Wrap in double-quotes → exact match instead of prefix search.
            $result = $this->client->graphql($query, ['query' => 'sku:"' . $escapedSku . '"']);
        } catch (\Exception $e) {
            \App\Utils\LogHelper::error('GraphQL SKU search failed', [
                'sku'   => $trimmedSku,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $edges = $result['data']['productVariants']['edges'] ?? [];
        if (empty($edges)) {
            return null;
        }

        // Exact-quote search is already precise, but do a final string comparison
        // as a safety net against unexpected Shopify tokenisation edge cases.
        $productId = null;
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            if (trim($node['sku'] ?? '') === $trimmedSku) {
                $productId = $node['product']['legacyResourceId'] ?? null;
                break;
            }
        }

        if (!$productId) {
            \App\Utils\LogHelper::warning('GraphQL SKU search returned variants but none matched exactly', [
                'sku'        => $trimmedSku,
                'candidates' => array_column(array_column($edges, 'node'), 'sku'),
            ]);
            return null;
        }

        // Fetch the full REST product by numeric ID for the update flow
        return $this->getProduct($productId);
    }

    /**
     * Create product
     */
    public function createProduct(array $productData)
    {
        $result = $this->client->post('products.json', ['product' => $productData]);
        return $result['product'] ?? null;
    }

    /**
     * Update product
     */
    public function updateProduct($productId, array $productData)
    {
        $result = $this->client->put("products/{$productId}.json", ['product' => $productData]);
        return $result['product'] ?? null;
    }

    /**
     * Get all products (paginated)
     */
    public function getAllProducts($limit = 250, $page = 1)
    {
        $result = $this->client->get('products.json', [
            'limit' => $limit,
            'page' => $page,
        ]);
        
        return $result['products'] ?? [];
    }
}

