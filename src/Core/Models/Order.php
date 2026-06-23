<?php

namespace App\Core\Models;

/**
 * Core Order Model
 * Provider-agnostic order representation
 */
class Order
{
    // Core identification
    public $id;
    public $orderNumber;
    public $name; // Shopify order name format
    
    // Customer
    public $customer;
    public $customerId;
    public $email;
    
    // Addresses
    public $billingAddress;
    public $shippingAddress;
    
    // Items
    public $items = [];
    
    // Financial
    public $subtotal;
    public $totalTax;
    public $totalShipping;
    public $totalDiscount;
    public $total;
    public $currency;
    
    // Shipping
    public $shippingMethod;
    public $shippingCost;
    public $trackingNumber;
    public $fulfillmentStatus; // unfulfilled, partial, fulfilled
    
    // Status
    public $status; // pending, paid, cancelled, refunded
    public $financialStatus;
    
    // Dates
    public $createdAt;
    public $updatedAt;
    public $cancelledAt;
    
    // Additional
    public $notes;
    public $tags = [];
    
    // Provider mappings
    public $mappedProviders = [];
    
    /**
     * Add or update provider mapping
     * @param string $providerId
     * @param array $mapping
     */
    public function setProviderMapping($providerId, array $mapping)
    {
        $this->mappedProviders[$providerId] = array_merge([
            'externalId' => null,
            'externalNumber' => null,
            'lastSync' => date('Y-m-d H:i:s'),
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
     * Convert to array
     * @return array
     */
    public function toArray()
    {
        return [
            'id' => $this->id,
            'orderNumber' => $this->orderNumber,
            'name' => $this->name,
            'customer' => $this->customer,
            'customerId' => $this->customerId,
            'email' => $this->email,
            'billingAddress' => $this->billingAddress,
            'shippingAddress' => $this->shippingAddress,
            'items' => $this->items,
            'subtotal' => $this->subtotal,
            'totalTax' => $this->totalTax,
            'totalShipping' => $this->totalShipping,
            'totalDiscount' => $this->totalDiscount,
            'total' => $this->total,
            'currency' => $this->currency,
            'shippingMethod' => $this->shippingMethod,
            'shippingCost' => $this->shippingCost,
            'trackingNumber' => $this->trackingNumber,
            'fulfillmentStatus' => $this->fulfillmentStatus,
            'status' => $this->status,
            'financialStatus' => $this->financialStatus,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'cancelledAt' => $this->cancelledAt,
            'notes' => $this->notes,
            'tags' => $this->tags,
            'mappedProviders' => $this->mappedProviders,
        ];
    }
}

