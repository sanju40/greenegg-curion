<?php

namespace App\Adapters\WwsRestService;

use App\Core\Contracts\AdapterInterface;
use App\Core\Models\Product;

/**
 * WWS Product Adapter
 * Converts WWS API data to/from Core Product Model
 */
class ProductAdapter implements AdapterInterface
{
    /**
     * Convert WWS product data to core Product model
     * @param array $wwsData Raw WWS API response
     * @return Product
     */
    public function toCoreModel(array $wwsData): Product
    {
        $product = new Product();

        // ── Core identification ──────────────────────────────────────────────
        $product->id = $wwsData['id'] ?? null;
        $product->sku = $wwsData['sku'] ?? null;
        $product->barcode = $wwsData['barcode'] ?? null;
        
        // Basic information
        $product->title = $this->getNestedValue($wwsData, 'name.value.D') ?? 
                         $this->getNestedValue($wwsData, 'name.value.0') ?? 
                         'Untitled Product';
        $product->description = $this->getNestedValue($wwsData, 'longDescription.value.D') ?? 
                               $this->getNestedValue($wwsData, 'longDescription.value.0') ?? 
                               '';
        $product->shortDescription = $this->getNestedValue($wwsData, 'shortDescription.value.D') ?? 
                                    $this->getNestedValue($wwsData, 'shortDescription.value.0') ?? 
                                    '';
        
        // Pricing
        // passantPrice is the customer-facing retail price returned by the detail endpoint.
        // The listing (productSearch) does not include salesPrices, so we fall back to
        // basePrice — which is the same price field available in both endpoints.
        $passantPrice = $this->getNestedValue($wwsData, 'salesPrices.0.passantPrice');
        $basePrice    = $wwsData['basePrice'] ?? null;
        $product->price          = $passantPrice ?? $basePrice ?? 0;
        $product->compareAtPrice = null; // only set a strike-through if passantPrice < basePrice
        if ($passantPrice !== null && $basePrice !== null && (float)$passantPrice < (float)$basePrice) {
            $product->compareAtPrice = $basePrice;
        }
        $product->cost = null; // WWS doesn't provide cost
        
        // Inventory
        $product->inventoryQty = $this->getNestedValue($wwsData, 'stock.quantityStock') ?? 0;
        $product->inventoryPolicy = 'deny';
        $product->inventoryManagement = 'shopify';
        
        // Categorization
        $product->vendor = $this->getNestedValue($wwsData, 'section.description.value.D') ?? 
                          $this->getNestedValue($wwsData, 'section.description.value.0') ?? 
                          'Big Green Egg';
        $product->productType = $this->getNestedValue($wwsData, 'goodsGroup.description.value.D') ?? 
                               $this->getNestedValue($wwsData, 'goodsGroup.description.value.0') ?? 
                               '';
        
        // Tags — no ERP identifiers (SKU, synonym, product-ID) to avoid polluting
        // the Shopify admin tag list. Sync-specific tags (ERP-*, API_PRODUCTS, etc.)
        // are appended by ProductSyncService before the product is sent to Shopify.
        $product->tags = [];
        
        // Status
        $product->status = ($wwsData['status'] ?? 1) == 1 ? 'active' : 'draft';
        
        // Additional
        $product->weight = $wwsData['weight'] ?? 0;
        $product->weightUnit = 'kg';

        // Do not map WWS image1 into core images: Shopify create/update rejects invalid URLs
        // with HTTP 422 ("Image URL is invalid"). Product imagery is managed in Shopify.

        // ── Bundle detection ─────────────────────────────────────────────────
        // stockManagement.id 101 = variable bundle, 102 = fixed bundle.
        // The list is driven by config so it can be updated without code changes.
        $config = \App\Core\Config::get();
        $bundleIds = $config['bundles']['stock_management_ids'] ?? [101, 102];
        $stockMgmtId = isset($wwsData['stockManagement']['id'])
            ? (int) $wwsData['stockManagement']['id']
            : null;

        if ($stockMgmtId !== null && in_array($stockMgmtId, $bundleIds, true)) {
            $product->isBundle = true;

            if (isset($wwsData['basePrice']) && $wwsData['basePrice'] !== '' && $wwsData['basePrice'] !== null) {
                $product->bundleBasePrice = (float) $wwsData['basePrice'];
            }

            // Map partsList → bundleComponents
            // Each entry keeps the minimum needed: the WWS product ID and quantity.
            // The BundleService will resolve the Shopify variant ID via product_mappings.
            $product->bundleComponents = array_values(array_filter(
                array_map(static function (array $part): ?array {
                    if (empty($part['productId'])) {
                        return null;
                    }
                    return [
                        'wws_product_id' => (int) $part['productId'],
                        'quantity'       => max(1, (int) ($part['quantity'] ?? 1)),
                    ];
                }, $wwsData['partsList'] ?? [])
            ));
        }

        return $product;
    }
    
    /**
     * Convert core Product model to WWS format
     * @param Product $product
     * @return array
     */
    public function fromCoreModel($product): array
    {
        // WWS typically doesn't accept product creation via API in this format
        // This would be used if we need to push data back to WWS
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            // Map other fields as needed
        ];
    }
    
    /**
     * Get nested value from array using dot notation
     * @param array $data
     * @param string|null $path
     * @return mixed
     */
    private function getNestedValue(array $data, $path)
    {
        if ($path === null) {
            return null;
        }
        
        $keys = explode('.', $path);
        $value = $data;
        
        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }
        
        return $value;
    }
}

