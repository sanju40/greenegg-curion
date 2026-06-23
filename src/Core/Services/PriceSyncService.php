<?php

namespace App\Core\Services;

use App\Core\Factory\ProviderFactory;
use App\Core\Contracts\ErpProviderInterface;
use App\Core\Contracts\EcommerceProviderInterface;
use App\Core\Conflict\ConflictResolver;
use App\Core\Models\Product;
use App\Adapters\Shopify\ProductAdapter as ShopifyAdapter;
use App\Utils\Logger;
use App\Utils\LogHelper;

/**
 * Price Sync Service
 * Handles price synchronization with conflict resolution
 */
class PriceSyncService
{
    private $erpProvider;
    private $ecommerceProvider;
    private $shopifyAdapter;
    private $conflictResolver;
    private $logger;

    public function __construct(
        ?ErpProviderInterface $erpProvider = null,
        ?EcommerceProviderInterface $ecommerceProvider = null
    ) {
        $this->erpProvider = $erpProvider ?? ProviderFactory::createErpProvider('wws');
        $this->ecommerceProvider = $ecommerceProvider ?? ProviderFactory::createEcommerceProvider('shopify');
        
        $this->shopifyAdapter = new ShopifyAdapter();
        $this->conflictResolver = new ConflictResolver();
        $this->logger = new Logger();
    }

    /**
     * Sync price for a product
     * @param Product $product Core product model
     * @param array $providerPrices Array of [providerId => price]
     * @return float Resolved price
     */
    public function syncPrice(Product $product, array $providerPrices = []): float
    {
        // Resolve price conflict
        $resolvedPrice = $this->conflictResolver->resolvePrice($product, $providerPrices);
        
        if ($resolvedPrice === null) {
            // No price resolved, keep current
            return $product->price ?? 0;
        }
        
        // Update product model
        $product->price = $resolvedPrice;
        
        // Update in e-commerce platform
        if ($product->id && $this->ecommerceProvider->checkCapability('pricing_write')) {
            try {
                $updateData = $this->shopifyAdapter->fromCoreModelLimited($product);
                $this->ecommerceProvider->updateProduct($product->id, $updateData);
                
                $this->logger->logSync(
                    'price_sync',
                    'product',
                    $product->id,
                    $product->id,
                    'success',
                    ['price' => $resolvedPrice],
                    null,
                    null
                );
            } catch (\Exception $e) {
                LogHelper::error('Failed to update price', [
                    'product_id' => $productId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $resolvedPrice;
    }
}

