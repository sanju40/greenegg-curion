<?php

namespace App\Core\Services;

use App\Core\Factory\ProviderFactory;
use App\Core\Contracts\ErpProviderInterface;
use App\Core\Contracts\EcommerceProviderInterface;
use App\Core\Conflict\ConflictResolver;
use App\Core\Models\Product;
use App\Adapters\WwsRestService\ProductAdapter as WwsAdapter;
use App\Adapters\Shopify\ProductAdapter as ShopifyAdapter;
use App\Utils\Logger;
use App\Utils\LogHelper;

/**
 * Inventory Sync Service
 * Handles inventory synchronization with multiple modes
 */
class InventorySyncService
{
    private $erpProvider;
    private $ecommerceProvider;
    private $wwsAdapter;
    private $shopifyAdapter;
    private $conflictResolver;
    private $logger;
    private $syncMode;

    public function __construct(
        ?ErpProviderInterface $erpProvider = null,
        ?EcommerceProviderInterface $ecommerceProvider = null
    ) {
        $this->erpProvider = $erpProvider ?? ProviderFactory::createErpProvider('wws');
        $this->ecommerceProvider = $ecommerceProvider ?? ProviderFactory::createEcommerceProvider('shopify');
        
        $this->wwsAdapter = new WwsAdapter();
        $this->shopifyAdapter = new ShopifyAdapter();
        $this->conflictResolver = new ConflictResolver();
        $this->logger = new Logger();
        
        $this->syncMode = \App\Core\Config::get('sync.inventory', [
            'mode' => 'provider_authoritative',
        ]);
    }

    /**
     * Sync inventory for a product
     * @param Product $product Core product model
     * @param array $providerInventories Array of [providerId => quantity]
     * @return int Resolved inventory quantity
     */
    public function syncInventory(Product $product, array $providerInventories = []): int
    {
        $mode = $this->syncMode['mode'] ?? 'provider_authoritative';
        
        switch ($mode) {
            case 'provider_authoritative':
                return $this->syncProviderAuthoritative($product, $providerInventories);
                
            case 'shopify_authoritative':
                return $this->syncShopifyAuthoritative($product);
                
            case 'hybrid':
                return $this->syncHybrid($product, $providerInventories);
                
            case 'bidirectional':
                return $this->syncBidirectional($product, $providerInventories);
                
            default:
                return $this->syncProviderAuthoritative($product, $providerInventories);
        }
    }

    /**
     * Provider authoritative mode - provider drives inventory
     * @param Product $product
     * @param array $providerInventories
     * @return int
     */
    private function syncProviderAuthoritative(Product $product, array $providerInventories): int
    {
        // Resolve inventory from providers
        $resolvedQty = $this->conflictResolver->resolveInventory($product, $providerInventories);
        
        // Update product model
        $product->inventoryQty = $resolvedQty;
        
        // Update in e-commerce platform using Inventory API
        if ($product->id && $this->ecommerceProvider->checkCapability('inventory_write')) {
            try {
                // For Shopify, use Inventory API (not Product API)
                if ($this->ecommerceProvider->getName() === 'shopify') {
                    $this->updateInventoryViaInventoryApi($product->id, $resolvedQty);
                } else {
                    // For other platforms, use Product API
                    $updateData = $this->shopifyAdapter->fromCoreModelLimited($product);
                    $this->ecommerceProvider->updateProduct($product->id, $updateData);
                }
                
                $this->logger->logSync(
                    'inventory_sync',
                    'product',
                    $product->id,
                    $product->id,
                    'success',
                    ['quantity' => $resolvedQty],
                    null,
                    null
                );
            } catch (\Exception $e) {
                LogHelper::error('Failed to update inventory', [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $resolvedQty;
    }

    /**
     * Shopify authoritative mode - Shopify manages inventory
     * @param Product $product
     * @return int
     */
    private function syncShopifyAuthoritative(Product $product): int
    {
        // Don't update from providers, just return current Shopify inventory
        return $product->inventoryQty ?? 0;
    }

    /**
     * Hybrid mode - merge inventory from multiple providers
     * @param Product $product
     * @param array $providerInventories
     * @return int
     */
    private function syncHybrid(Product $product, array $providerInventories): int
    {
        // Merge inventory from multiple providers
        $mergedQty = $this->conflictResolver->resolveInventory($product, $providerInventories);
        $product->inventoryQty = $mergedQty;
        
        // Update Shopify using Inventory API
        if ($product->id && $this->ecommerceProvider->checkCapability('inventory_write')) {
            try {
                if ($this->ecommerceProvider->getName() === 'shopify') {
                    $this->updateInventoryViaInventoryApi($product->id, $mergedQty);
                } else {
                    $updateData = $this->shopifyAdapter->fromCoreModelLimited($product);
                    $this->ecommerceProvider->updateProduct($product->id, $updateData);
                }
            } catch (\Exception $e) {
                LogHelper::error('Failed to update inventory', [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $mergedQty;
    }

    /**
     * Bidirectional mode - sync both ways with conflict resolution
     * @param Product $product
     * @param array $providerInventories
     * @return int
     */
    private function syncBidirectional(Product $product, array $providerInventories): int
    {
        // More complex logic for bidirectional sync
        // For now, use provider authoritative
        return $this->syncProviderAuthoritative($product, $providerInventories);
    }

    /**
     * Update inventory using Shopify Inventory API
     * @param mixed $shopifyProductId
     * @param int $quantity
     */
    private function updateInventoryViaInventoryApi($shopifyProductId, $quantity): void
    {
        try {
            // Get product to find variant and inventory_item_id
            $shopifyProduct = $this->ecommerceProvider->getProduct($shopifyProductId);
            if (!$shopifyProduct || empty($shopifyProduct['variants'])) {
                return;
            }

            $inventoryService = new \App\Api\Shopify\InventoryService();
            
            // Get all locations
            $locations = $inventoryService->getLocations();
            if (empty($locations)) {
                LogHelper::warning('No inventory locations found. Cannot update inventory via Inventory API');
                return;
            }

            // Update inventory for first variant (or all variants if needed)
            $primaryVariant = $shopifyProduct['variants'][0];
            $inventoryItemId = $primaryVariant['inventory_item_id'] ?? null;
            
            if ($inventoryItemId) {
                $primaryLocation = $locations[0];
                $locationId = $primaryLocation['id'] ?? null;
                
                if ($locationId) {
                    $inventoryService->setInventoryLevel($inventoryItemId, $locationId, $quantity);
                }
            }
        } catch (\Exception $e) {
            LogHelper::error('Failed to update inventory via Inventory API', [
                'inventory_item_id' => $inventoryItemId,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

