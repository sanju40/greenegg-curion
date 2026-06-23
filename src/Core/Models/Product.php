<?php

namespace App\Core\Models;

/**
 * Core Product Model
 * Provider-agnostic product representation
 */
class Product
{
    // Core identification
    public $id;
    public $sku;
    public $barcode;
    
    // Basic information
    public $title;
    public $description;
    public $shortDescription;
    public $images = [];
    
    // Pricing
    public $price;
    public $compareAtPrice;
    public $cost;
    
    // Inventory
    public $inventoryQty;
    public $inventoryPolicy; // deny, continue
    public $inventoryManagement; // shopify, null
    
    // Categorization
    public $vendor;
    public $productType;
    public $tags = [];
    
    // Status
    public $status; // draft, active, archived
    
    // Additional attributes
    public $attributes = [];
    public $weight;
    public $weightUnit;

    // Bundle support
    // isBundle: true when WWS stockManagement.id is 101 or 102
    public $isBundle = false;
    // bundleComponents: mapped from WWS partsList
    // Each entry: ['wws_product_id' => int, 'quantity' => int]
    public $bundleComponents = [];

    /** WWS basePrice for bundle SKUs — used as Shopify bundle variant price when set (> 0). */
    public $bundleBasePrice = null;
    
    // Provider mappings
    public $mappedProviders = [];
    
    /**
     * Add or update provider mapping
     * @param string $providerId Provider identifier
     * @param array $mapping Mapping data
     */
    public function setProviderMapping($providerId, array $mapping)
    {
        $this->mappedProviders[$providerId] = array_merge([
            'externalId' => null,
            'externalSku' => $this->sku,
            'inventoryQty' => null,
            'price' => null,
            'lastSync' => date('Y-m-d H:i:s'),
            'authoritative' => false,
        ], $mapping);
    }
    
    /**
     * Get provider mapping
     * @param string $providerId
     * @return array|null
     */
    public function getProviderMapping($providerId)
    {
        return $this->mappedProviders[$providerId] ?? null;
    }
    
    /**
     * Get authoritative provider ID
     * @return string|null
     */
    public function getAuthoritativeProvider()
    {
        foreach ($this->mappedProviders as $providerId => $mapping) {
            if ($mapping['authoritative'] ?? false) {
                return $providerId;
            }
        }
        return null;
    }
    
    /**
     * Check if product is mapped to provider
     * @param string $providerId
     * @return bool
     */
    public function isMappedToProvider($providerId)
    {
        return isset($this->mappedProviders[$providerId]);
    }
    
    /**
     * Set authoritative provider
     * @param string $providerId
     */
    public function setAuthoritativeProvider($providerId)
    {
        // Remove authoritative flag from all providers
        foreach ($this->mappedProviders as $pid => &$mapping) {
            $mapping['authoritative'] = false;
        }
        
        // Set new authoritative provider
        if (isset($this->mappedProviders[$providerId])) {
            $this->mappedProviders[$providerId]['authoritative'] = true;
        }
    }
    
    /**
     * Convert to array
     * @return array
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'title' => $this->title,
            'description' => $this->description,
            'shortDescription' => $this->shortDescription,
            'images' => $this->images,
            'price' => $this->price,
            'compareAtPrice' => $this->compareAtPrice,
            'cost' => $this->cost,
            'inventoryQty' => $this->inventoryQty,
            'inventoryPolicy' => $this->inventoryPolicy,
            'inventoryManagement' => $this->inventoryManagement,
            'vendor' => $this->vendor,
            'productType' => $this->productType,
            'tags' => $this->tags,
            'status' => $this->status,
            'attributes' => $this->attributes,
            'weight' => $this->weight,
            'weightUnit' => $this->weightUnit,
            'mappedProviders' => $this->mappedProviders,
            'isBundle' => $this->isBundle,
            'bundleComponents' => $this->bundleComponents,
            'bundleBasePrice' => $this->bundleBasePrice,
        ];
    }
}

