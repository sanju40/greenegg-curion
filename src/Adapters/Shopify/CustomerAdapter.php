<?php

namespace App\Adapters\Shopify;

use App\Core\Contracts\AdapterInterface;
use App\Core\Models\Customer;

/**
 * Shopify Customer Adapter
 * Converts Shopify API data to/from Core Customer Model
 */
class CustomerAdapter implements AdapterInterface
{
    /**
     * Convert Shopify customer data to core Customer model
     * @param array $shopifyData Raw Shopify API response
     * @return Customer
     */
    public function toCoreModel(array $shopifyData): Customer
    {
        $customer = new Customer();
        
        // Core identification
        $customer->id = (string)($shopifyData['id'] ?? null);
        $customer->email = $shopifyData['email'] ?? null;
        $customer->phone = $shopifyData['phone'] ?? null;
        
        // Name
        $customer->firstName = $shopifyData['first_name'] ?? null;
        $customer->lastName = $shopifyData['last_name'] ?? null;
        $customer->fullName = trim(($customer->firstName ?? '') . ' ' . ($customer->lastName ?? ''));
        
        // Company
        $customer->company = $shopifyData['company'] ?? null;
        
        // Addresses
        if (!empty($shopifyData['addresses']) && is_array($shopifyData['addresses'])) {
            $customer->addresses = $shopifyData['addresses'];
            
            // Find default address
            foreach ($shopifyData['addresses'] as $addr) {
                if ($addr['default'] ?? false) {
                    $customer->defaultAddress = $addr;
                    break;
                }
            }
        }
        
        // Additional
        $customer->notes = $shopifyData['note'] ?? null;
        $customer->tags = !empty($shopifyData['tags']) ? explode(', ', $shopifyData['tags']) : [];
        $customer->acceptsMarketing = $shopifyData['accepts_marketing'] ?? false;
        
        return $customer;
    }
    
    /**
     * Convert core Customer model to Shopify format
     * @param Customer $customer
     * @return array
     */
    public function fromCoreModel($customer): array
    {
        // Shopify requires at least first_name or last_name
        // If both are empty, use fullName or a default
        $firstName = $customer->firstName;
        $lastName = $customer->lastName;
        
        if (empty($firstName) && empty($lastName)) {
            if (!empty($customer->fullName)) {
                $nameParts = explode(' ', trim($customer->fullName), 2);
                $firstName = $nameParts[0] ?? 'Customer';
                $lastName = $nameParts[1] ?? '';
            } else {
                $firstName = 'Customer';
                $lastName = '';
            }
        }
        
        $shopifyCustomer = [
            'email' => $customer->email,
            'phone' => $customer->phone ?? null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $customer->company ?? null,
            'note' => $customer->notes ?? null,
            'tags' => is_array($customer->tags) ? implode(', ', $customer->tags) : ($customer->tags ?? ''),
            'accepts_marketing' => $customer->acceptsMarketing ?? false,
        ];
        
        // Remove null values from main customer data, but keep tags even if empty string
        $shopifyCustomer = array_filter($shopifyCustomer, function($value, $key) {
            // Always keep tags field, even if empty (Shopify will handle it)
            if ($key === 'tags') {
                return true;
            }
            return $value !== null;
        }, ARRAY_FILTER_USE_BOTH);
        
        // Add addresses if available
        if (!empty($customer->addresses)) {
            $shopifyCustomer['addresses'] = [];
            foreach ($customer->addresses as $addr) {
                // Ensure country is a string, not an object
                $country = $addr['country'] ?? null;
                if (is_array($country)) {
                    $country = $country['code'] ?? $country['name'] ?? $country['id'] ?? null;
                }
                
                // Extract name from address or use customer name
                $addrFirstName = $addr['first_name'] ?? $addr['firstName'] ?? $firstName ?? null;
                $addrLastName = $addr['last_name'] ?? $addr['lastName'] ?? $lastName ?? null;
                
                $shopifyAddress = [
                    'address1' => $addr['address1'] ?? null,
                    'address2' => $addr['address2'] ?? null,
                    'city' => $addr['city'] ?? null,
                    'province' => $addr['province'] ?? $addr['state'] ?? null,
                    'zip' => $addr['zip'] ?? $addr['postalCode'] ?? $addr['postcode'] ?? null,
                    'country' => $country,
                    'first_name' => $addrFirstName,
                    'last_name' => $addrLastName,
                    'company' => $addr['company'] ?? $customer->company ?? null,
                    'phone' => $addr['phone'] ?? $customer->phone ?? null,
                    'default' => $addr === $customer->defaultAddress || ($customer->defaultAddress === null && empty($shopifyCustomer['addresses'])),
                ];
                
                // Remove null values but keep empty strings for required fields
                $filteredAddress = [];
                foreach ($shopifyAddress as $key => $value) {
                    // Keep 'default' field even if false
                    if ($key === 'default') {
                        $filteredAddress[$key] = $value;
                    } elseif ($value !== null && $value !== '') {
                        $filteredAddress[$key] = $value;
                    }
                }
                $shopifyAddress = $filteredAddress;
                
                // Always add address if we have any meaningful data
                // Shopify allows addresses with just name and country
                if (!empty($shopifyAddress)) {
                    // Ensure at least first_name or last_name is present for address
                    if (isset($shopifyAddress['first_name']) || isset($shopifyAddress['last_name']) || isset($shopifyAddress['company'])) {
                        $shopifyCustomer['addresses'][] = $shopifyAddress;
                    }
                }
            }
        }
        
        return $shopifyCustomer;
    }
}

