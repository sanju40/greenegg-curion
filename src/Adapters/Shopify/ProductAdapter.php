<?php

namespace App\Adapters\Shopify;

use App\Core\Contracts\AdapterInterface;
use App\Core\Models\Product;

/**
 * Shopify Product Adapter
 * Converts Shopify API data to/from Core Product Model
 */
class ProductAdapter implements AdapterInterface
{
    /**
     * Convert Shopify product data to core Product model
     * @param array $shopifyData Raw Shopify API response
     * @return Product
     */
    public function toCoreModel(array $shopifyData): Product
    {
        $product = new Product();
        
        // Core identification
        $product->id = (string)($shopifyData['id'] ?? null);
        $product->sku = $shopifyData['variants'][0]['sku'] ?? null;
        $product->barcode = $shopifyData['variants'][0]['barcode'] ?? null;
        
        // Basic information
        $product->title = $shopifyData['title'] ?? 'Untitled Product';
        $product->description = $shopifyData['body_html'] ?? '';
        $product->shortDescription = null; // Shopify doesn't have short description
        
        // Images
        $product->images = [];
        if (!empty($shopifyData['images']) && is_array($shopifyData['images'])) {
            foreach ($shopifyData['images'] as $image) {
                $product->images[] = [
                    'src' => $image['src'] ?? null,
                    'alt' => $image['alt'] ?? null,
                ];
            }
        }
        
        // Pricing (from first variant)
        $variant = $shopifyData['variants'][0] ?? [];
        $product->price = (float)($variant['price'] ?? 0);
        $product->compareAtPrice = isset($variant['compare_at_price']) ? (float)$variant['compare_at_price'] : null;
        $product->cost = isset($variant['cost']) ? (float)$variant['cost'] : null;
        
        // Inventory
        $product->inventoryQty = (int)($variant['inventory_quantity'] ?? 0);
        $product->inventoryPolicy = $variant['inventory_policy'] ?? 'deny';
        $product->inventoryManagement = $variant['inventory_management'] ?? 'shopify';
        
        // Categorization
        $product->vendor = $shopifyData['vendor'] ?? '';
        $product->productType = $shopifyData['product_type'] ?? '';
        $product->tags = !empty($shopifyData['tags']) ? explode(', ', $shopifyData['tags']) : [];
        
        // Status
        $product->status = $shopifyData['status'] ?? 'active';
        
        // Additional
        $product->weight = isset($variant['weight']) ? (float)$variant['weight'] : 0;
        $product->weightUnit = $variant['weight_unit'] ?? 'kg';
        
        return $product;
    }
    
    /**
     * Convert core Product model to Shopify format
     * @param Product $product
     * @param array|null $existingVariant If updating existing variant, pass it to preserve other fields
     * @return array
     */
    public function fromCoreModel($product, ?array $existingVariant = null): array
    {
        $shopifyProduct = [
            'title' => $product->title,
            'body_html' => $product->description,
            'vendor' => $product->vendor,
            'product_type' => $product->productType,
            'tags' => is_array($product->tags) ? implode(', ', $product->tags) : $product->tags,
            'status' => $product->status ?? 'draft',
            'published_scope' => 'global', // Publish to online store sales channel
            'published' => true, // Publish to online store sales channel
            'published_at' => date('Y-m-d H:i:s'),
        ];
        
        // Build variant - if updating existing, merge to preserve other fields
        if ($existingVariant !== null) {
            // Update existing variant - preserve all fields, only update what we have
            $variant = $existingVariant;
            $variant['sku'] = $product->sku;
            $variant['price'] = (string)$product->price;
            $variant['compare_at_price'] = $product->compareAtPrice ? (string)$product->compareAtPrice : null;
            $variant['inventory_quantity'] = $product->inventoryQty;
            if ($product->inventoryPolicy !== null) {
                $variant['inventory_policy'] = $product->inventoryPolicy;
            }
            if ($product->inventoryManagement !== null) {
                $variant['inventory_management'] = $product->inventoryManagement;
            }
            if ($product->barcode !== null) {
                $variant['barcode'] = $product->barcode;
            }
            if ($product->weight !== null) {
                $variant['weight'] = $product->weight;
            }
            if ($product->weightUnit !== null) {
                $variant['weight_unit'] = $product->weightUnit;
            }
        } else {
            // New variant
            $variant = [
                'sku' => $product->sku,
                'price' => (string)$product->price,
                'compare_at_price' => $product->compareAtPrice ? (string)$product->compareAtPrice : null,
                'inventory_quantity' => $product->inventoryQty,
                'inventory_policy' => $product->inventoryPolicy ?? 'deny',
                'inventory_management' => $product->inventoryManagement ?? 'shopify',
                'barcode' => $product->barcode,
                'weight' => $product->weight,
                'weight_unit' => $product->weightUnit ?? 'kg',
            ];
        }
        
        $shopifyProduct['variants'] = [$variant];
        
        // Add images if available
        if (!empty($product->images)) {
            $shopifyProduct['images'] = [];
            foreach ($product->images as $image) {
                $shopifyProduct['images'][] = [
                    'src' => $image['src'] ?? $image['url'] ?? null,
                    'alt' => $image['alt'] ?? null,
                ];
            }
        }
        
        return $shopifyProduct;
    }
    
    /**
     * Convert core Product model to Shopify format (limited - price/inventory only)
     * Uses PATCH-like approach: only sends fields we want to update
     * Shopify REST API requires variant ID and will preserve other fields if we only send what we're updating
     * @param Product $product
     * @param array $existingVariant Existing variant data from Shopify (to get variant ID)
     * @return array
     */
    public function fromCoreModelLimited($product, array $existingVariant = []): array
    {
        // Build minimal variant update - only fields we want to change
        // Shopify will preserve all other fields (SKU, barcode, weight, etc.) when we only send these
        $variant = [];
        
        // Variant ID is required for update
        if (!empty($existingVariant['id'])) {
            $variant['id'] = $existingVariant['id'];
        }
        
        // Only include fields we're updating
        $variant['price'] = (string)$product->price;

        if ($product->compareAtPrice !== null) {
            $variant['compare_at_price'] = (string)$product->compareAtPrice;
        }

        // Always enable inventory tracking so the Inventory API call that follows
        // can set the quantity. Products created without tracking (or those whose
        // tracking was disabled by Shopify Bundles) will have it re-enabled here.
        $variant['inventory_management'] = 'shopify';

        // NOTE: inventory_quantity is intentionally NOT set here.
        // Shopify ignores it via the Product API when inventory locations are in use.
        // Inventory is updated separately via InventoryService in ProductSyncService.

        return [
            'variants' => [$variant],
        ];
    }
}

