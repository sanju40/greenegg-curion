<?php

namespace App\Core\Models;

/**
 * Core Customer Model
 * Provider-agnostic customer representation
 */
class Customer
{
    // Core identification
    public $id;
    public $email;
    public $phone;
    public $customerNumber; // Provider-specific customer number
    
    // Name
    public $firstName;
    public $lastName;
    public $fullName;
    
    // Address
    public $addresses = [];
    public $defaultAddress = null;
    public $billingAddress = null;
    public $shippingAddress = null;
    
    // Additional
    public $company;
    public $notes;
    public $tags = [];
    public $acceptsMarketing = false;
    
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
            'email' => $this->email,
            'phone' => $this->phone,
            'customerNumber' => $this->customerNumber,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'fullName' => $this->fullName,
            'addresses' => $this->addresses,
            'defaultAddress' => $this->defaultAddress,
            'billingAddress' => $this->billingAddress,
            'shippingAddress' => $this->shippingAddress,
            'company' => $this->company,
            'notes' => $this->notes,
            'tags' => $this->tags,
            'acceptsMarketing' => $this->acceptsMarketing,
            'mappedProviders' => $this->mappedProviders,
        ];
    }
}

