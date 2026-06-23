<?php

namespace App\Api\Shopify;

/**
 * Shopify Metafield Service
 * Handles product metafield operations
 */
class MetafieldService
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get all metafields for a product
     */
    public function getProductMetafields($productId): array
    {
        $result = $this->client->get("products/{$productId}/metafields.json");
        return $result['metafields'] ?? [];
    }

    /**
     * Find a product metafield by namespace + key
     */
    public function findProductMetafield($productId, string $namespace, string $key): ?array
    {
        $metafields = $this->getProductMetafields($productId);
        foreach ($metafields as $mf) {
            if (($mf['namespace'] ?? null) === $namespace && ($mf['key'] ?? null) === $key) {
                return $mf;
            }
        }
        return null;
    }

    /**
     * Create a metafield on a product
     */
    public function createProductMetafield($productId, string $namespace, string $key, string $type, $value): array
    {
        $payload = [
            'metafield' => [
                'namespace' => $namespace,
                'key' => $key,
                'type' => $type,
                'value' => $value,
            ],
        ];

        $result = $this->client->post("products/{$productId}/metafields.json", $payload);
        return $result['metafield'] ?? [];
    }

    /**
     * Update a metafield by metafield id
     */
    public function updateMetafield($metafieldId, $value, ?string $type = null): array
    {
        $mf = [
            'id' => $metafieldId,
            'value' => $value,
        ];
        if ($type !== null) {
            $mf['type'] = $type;
        }

        $result = $this->client->put("metafields/{$metafieldId}.json", ['metafield' => $mf]);
        return $result['metafield'] ?? [];
    }

    /**
     * Upsert a product metafield by namespace+key
     */
    public function upsertProductMetafield($productId, string $namespace, string $key, string $type, $value): array
    {
        $existing = $this->findProductMetafield($productId, $namespace, $key);
        if ($existing && isset($existing['id'])) {
            return $this->updateMetafield($existing['id'], $value, $type);
        }
        return $this->createProductMetafield($productId, $namespace, $key, $type, $value);
    }

    /**
     * Get all metafields for an order
     */
    public function getOrderMetafields($orderId): array
    {
        $result = $this->client->get("orders/{$orderId}/metafields.json");
        return $result['metafields'] ?? [];
    }

    /**
     * Find an order metafield by namespace + key
     */
    public function findOrderMetafield($orderId, string $namespace, string $key): ?array
    {
        $metafields = $this->getOrderMetafields($orderId);
        foreach ($metafields as $mf) {
            if (($mf['namespace'] ?? null) === $namespace && ($mf['key'] ?? null) === $key) {
                return $mf;
            }
        }
        return null;
    }

    /**
     * Create a metafield on an order
     */
    public function createOrderMetafield($orderId, string $namespace, string $key, string $type, $value): array
    {
        $payload = [
            'metafield' => [
                'namespace' => $namespace,
                'key' => $key,
                'type' => $type,
                'value' => $value,
            ],
        ];

        $result = $this->client->post("orders/{$orderId}/metafields.json", $payload);
        return $result['metafield'] ?? [];
    }

    /**
     * Upsert an order metafield by namespace+key
     */
    public function upsertOrderMetafield($orderId, string $namespace, string $key, string $type, $value): array
    {
        $existing = $this->findOrderMetafield($orderId, $namespace, $key);
        if ($existing && isset($existing['id'])) {
            return $this->updateMetafield($existing['id'], $value, $type);
        }
        return $this->createOrderMetafield($orderId, $namespace, $key, $type, $value);
    }
}


