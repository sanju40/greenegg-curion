<?php

namespace App\Core\Routing;

use App\Database\Repository\ProductMappingRepository;
use App\Core\Registry\ProviderRegistry;

/**
 * Order Router
 * Routes Shopify orders to appropriate ERP providers
 */
class OrderRouter
{
    private $rules;
    private $productMappingRepository;
    private $providerRegistry;

    public function __construct()
    {
        $this->rules = require BASE_PATH . '/config/order-routing.php';
        $this->productMappingRepository = new ProductMappingRepository();
        $this->providerRegistry = new ProviderRegistry();
    }

    /**
     * Route order to providers
     * @param array $shopifyOrder Shopify order data
     * @return array Array of routes: [providerId => [items, order_data]]
     */
    public function routeOrder(array $shopifyOrder): array
    {
        $routes = [];
        
        foreach ($shopifyOrder['line_items'] ?? [] as $item) {
            $sku = $item['sku'] ?? null;
            if (!$sku) {
                // Skip items without SKU
                continue;
            }
            
            $provider = $this->determineProvider($sku, $item);
            
            if (!isset($routes[$provider])) {
                $routes[$provider] = [
                    'provider' => $provider,
                    'items' => [],
                    'order_data' => $shopifyOrder,
                ];
            }
            
            $routes[$provider]['items'][] = $item;
        }
        
        return $routes;
    }

    /**
     * Determine which provider should handle this SKU
     * @param string $sku
     * @param array $item
     * @return string Provider name
     */
    private function determineProvider(string $sku, array $item): string
    {
        // 1. Check SKU-based rules
        if ($this->rules['strategies']['sku_based']['enabled'] ?? false) {
            foreach ($this->rules['strategies']['sku_based']['rules'] as $pattern => $provider) {
                if (preg_match($pattern, $sku)) {
                    return $provider;
                }
            }
        }
        
        // 2. Check product mapping
        if ($this->rules['strategies']['product_mapping']['enabled'] ?? false) {
            $mapping = $this->productMappingRepository->findBySku($sku);
            if ($mapping) {
                // If provider_id exists in mapping, get provider name from registry
                if (isset($mapping['provider_id']) && $mapping['provider_id']) {
                    $provider = $this->providerRegistry->getProviderById((int)$mapping['provider_id']);
                    if ($provider && !empty($provider['name'])) {
                        return $provider['name'];
                    }
                }
                // If mapping exists but no provider_id, default to WWS (backward compatibility)
                return 'wws';
            }
        }
        
        // 3. Priority routing
        if ($this->rules['strategies']['priority_routing']['enabled'] ?? false) {
            $priorityOrder = $this->rules['strategies']['priority_routing']['priority_order'] ?? [];
            if (!empty($priorityOrder)) {
                return $priorityOrder[0]; // Use first provider in priority
            }
        }
        
        // 4. Default provider
        return $this->rules['default_provider'] ?? 'wws';
    }

    /**
     * Check if order can be split across providers
     * @return bool
     */
    public function canSplitOrders(): bool
    {
        return $this->rules['strategies']['split_orders']['enabled'] ?? false;
    }
}

