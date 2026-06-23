<?php

namespace App\Core\Conflict;

use App\Core\Models\Product;

/**
 * Conflict Resolver
 * Resolves conflicts between providers (SKU, price, inventory)
 */
class ConflictResolver
{
    private $rules;

    public function __construct()
    {
        $this->rules = require BASE_PATH . '/config/conflict-rules.php';
    }

    /**
     * Resolve SKU identity conflicts
     * @param string $sku
     * @param array $providers Array of provider data with same SKU
     * @return string|null Provider ID that should be authoritative
     */
    public function resolveSkuIdentity(string $sku, array $providers): ?string
    {
        // Rule: One provider must be authoritative for SKU
        $authoritative = $this->rules['sku_authority'] ?? null;
        
        if ($authoritative && isset($providers[$authoritative])) {
            return $authoritative;
        }
        
        // Fallback: Use priority order
        return $this->getProviderByPriority($providers, $this->rules['price_priority_order'] ?? []);
    }

    /**
     * Resolve price conflicts
     * @param Product $product
     * @param array $providerPrices Array of [providerId => price]
     * @return float|null Resolved price
     */
    public function resolvePrice(Product $product, array $providerPrices): ?float
    {
        if (empty($providerPrices)) {
            return $product->price ?? null;
        }

        $strategy = $this->rules['price_strategy'] ?? 'provider_authoritative';
        
        switch ($strategy) {
            case 'provider_authoritative':
                $authProvider = $product->getAuthoritativeProvider();
                if ($authProvider && isset($providerPrices[$authProvider])) {
                    return (float)$providerPrices[$authProvider];
                }
                // Fallback to first provider
                return (float)reset($providerPrices);
                
            case 'shopify_authoritative':
                return $product->price ?? null;
                
            case 'highest':
                return (float)max($providerPrices);
                
            case 'lowest':
                return (float)min($providerPrices);
                
            case 'priority_provider':
                return $this->getPriceByPriority($providerPrices);
                
            default:
                return (float)reset($providerPrices);
        }
    }

    /**
     * Resolve inventory conflicts
     * @param Product $product
     * @param array $providerInventories Array of [providerId => quantity]
     * @return int Resolved inventory quantity
     */
    public function resolveInventory(Product $product, array $providerInventories): int
    {
        if (empty($providerInventories)) {
            return $product->inventoryQty ?? 0;
        }

        $strategy = $this->rules['inventory_strategy'] ?? 'sum';
        
        switch ($strategy) {
            case 'sum':
                return (int)array_sum($providerInventories);
                
            case 'highest':
                return (int)max($providerInventories);
                
            case 'lowest':
                return (int)min($providerInventories);
                
            case 'authoritative':
                $authProvider = $product->getAuthoritativeProvider();
                if ($authProvider && isset($providerInventories[$authProvider])) {
                    return (int)$providerInventories[$authProvider];
                }
                return (int)reset($providerInventories);
                
            case 'location_based':
                // For future: merge by warehouse location
                return (int)array_sum($providerInventories);
                
            default:
                return (int)array_sum($providerInventories);
        }
    }

    /**
     * Get provider by priority order
     * @param array $providers
     * @param array $priorityOrder
     * @return string|null
     */
    private function getProviderByPriority(array $providers, array $priorityOrder): ?string
    {
        foreach ($priorityOrder as $providerName) {
            if (isset($providers[$providerName])) {
                return $providerName;
            }
        }
        
        // Return first available provider
        return !empty($providers) ? array_key_first($providers) : null;
    }

    /**
     * Get price by priority order
     * @param array $providerPrices
     * @return float
     */
    private function getPriceByPriority(array $providerPrices): float
    {
        $priorityOrder = $this->rules['price_priority_order'] ?? [];
        
        foreach ($priorityOrder as $providerName) {
            if (isset($providerPrices[$providerName])) {
                return (float)$providerPrices[$providerName];
            }
        }
        
        return (float)reset($providerPrices);
    }
}

