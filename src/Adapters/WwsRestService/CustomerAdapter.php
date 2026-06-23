<?php

namespace App\Adapters\WwsRestService;

use App\Core\Contracts\AdapterInterface;
use App\Core\Models\Customer;

/**
 * WWS Customer Adapter
 * Converts WWS API data to/from Core Customer Model
 */
class CustomerAdapter implements AdapterInterface
{
    /**
     * Convert WWS customer data to core Customer model
     * @param array $wwsData Raw WWS API response
     * @return Customer
     */
    public function toCoreModel(array $wwsData): Customer
    {
        $customer = new Customer();
        
        // Core identification
        // WWS API may return customer in nested structure, extract ID from multiple possible locations
        $customer->id = (string)($wwsData['id'] ?? $wwsData['address']['id'] ?? null);
        $customer->customerNumber = $wwsData['customerNumber'] 
            ?? $wwsData['number'] 
            ?? $wwsData['address']['number'] 
            ?? null;
        
        // Contact information
        // Check multiple possible locations for email
        $customer->email = $wwsData['email'] 
            ?? $wwsData['address']['email'] ?? null
            ?? $wwsData['address']['emailAddress'] ?? null
            ?? $wwsData['address']['eMail'] ?? null;
        
        // Check multiple possible locations for phone
        $customer->phone = $wwsData['phone'] 
            ?? $wwsData['address']['phone'] ?? null
            ?? $wwsData['address']['phoneNumber'] ?? null
            ?? $wwsData['address']['telephone'] ?? null;
        
        // Name - check multiple locations including address and description fields
        $customer->firstName = $wwsData['firstName'] 
            ?? $wwsData['first_name'] 
            ?? ($wwsData['address']['firstName'] ?? null)
            ?? ($wwsData['address']['first_name'] ?? null);
        
        $customer->lastName = $wwsData['lastName'] 
            ?? $wwsData['last_name'] 
            ?? ($wwsData['address']['lastName'] ?? null)
            ?? ($wwsData['address']['last_name'] ?? null);
        
        // If no first/last name, try to extract from description or name field
        if (empty($customer->firstName) && empty($customer->lastName)) {
            $name = $wwsData['address']['name'] 
                ?? $wwsData['address']['description'] 
                ?? $wwsData['shortDescription'] 
                ?? $wwsData['longDescription'] 
                ?? null;
            if ($name) {
                // Try to split name into first and last
                $nameParts = explode(' ', trim($name), 2);
                $customer->firstName = $nameParts[0] ?? null;
                $customer->lastName = $nameParts[1] ?? null;
            }
        }
        
        $customer->fullName = trim(($customer->firstName ?? '') . ' ' . ($customer->lastName ?? ''));
        if (empty($customer->fullName)) {
            $customer->fullName = $wwsData['longDescription'] ?? $wwsData['shortDescription'] ?? null;
        }
        
        // Company
        $customer->company = $wwsData['company'] 
            ?? $wwsData['companyName'] 
            ?? ($wwsData['address']['company'] ?? null)
            ?? ($wwsData['address']['companyName'] ?? null);
        
        // Addresses
        // WWS API structure: customer has nested 'address' object (singular)
        // Check if address is nested in 'address' object
        if (!empty($wwsData['address']) && is_array($wwsData['address'])) {
            $addr = $wwsData['address'];
            
            // Extract country code/name from nested structure
            $country = null;
            if (isset($addr['country'])) {
                if (is_string($addr['country'])) {
                    $country = $addr['country'];
                } elseif (is_array($addr['country']) && isset($addr['country']['id'])) {
                    // Country is an object, try to get code or name
                    $country = $addr['country']['code'] 
                        ?? $addr['country']['name'] 
                        ?? $addr['country']['description']['value']['D'] 
                        ?? null;
                }
            }
            
            $address = [
                'address1' => $addr['street'] ?? $addr['street1'] ?? $addr['address1'] ?? $addr['addressLine1'] ?? null,
                'address2' => $addr['street2'] ?? $addr['address2'] ?? $addr['addressLine2'] ?? null,
                'city' => $addr['city'] ?? $addr['cityName'] ?? $addr['place'] ?? null,
                'province' => $addr['state'] ?? $addr['province'] ?? $addr['region'] ?? $addr['stateCode'] ?? null,
                'zip' => $addr['postalCode'] ?? $addr['zip'] ?? $addr['zipCode'] ?? $addr['postcode'] ?? null,
                'country' => $country,
                'first_name' => $addr['firstName'] ?? $addr['first_name'] ?? null,
                'last_name' => $addr['lastName'] ?? $addr['last_name'] ?? null,
                'company' => $addr['company'] ?? $addr['companyName'] ?? null,
                'phone' => $addr['phone'] ?? $addr['phone1'] ?? $addr['phoneNumber'] ?? $addr['telephone'] ?? null,
                'email' => $addr['email'] ?? $addr['emailAddress'] ?? $addr['eMail'] ?? null,
            ];
            
            // If name fields are empty, try to extract from description or name field
            if (empty($address['first_name']) && empty($address['last_name'])) {
                $name = $addr['name'] ?? $addr['description'] ?? $wwsData['shortDescription'] ?? null;
                if ($name) {
                    // Try to split name into first and last
                    $nameParts = explode(' ', $name, 2);
                    $address['first_name'] = $nameParts[0] ?? null;
                    $address['last_name'] = $nameParts[1] ?? null;
                }
            }
            
            // Remove null values to keep address clean
            $address = array_filter($address, function($value) {
                return $value !== null && $value !== '';
            });
            
            // Always add address if we have any data, even if minimal
            // Shopify can create customers with addresses that have just name and country
            if (!empty($address)) {
                $customer->addresses = [$address];
                $customer->defaultAddress = $address;
            }
        }
        // Check if addresses are in an array
        elseif (!empty($wwsData['addresses']) && is_array($wwsData['addresses'])) {
            $customer->addresses = [];
            foreach ($wwsData['addresses'] as $addr) {
                $address = [
                    'address1' => $addr['street'] ?? $addr['address1'] ?? $addr['street1'] ?? null,
                    'address2' => $addr['address2'] ?? $addr['street2'] ?? null,
                    'city' => $addr['city'] ?? null,
                    'province' => $addr['state'] ?? $addr['province'] ?? $addr['region'] ?? null,
                    'zip' => $addr['postalCode'] ?? $addr['zip'] ?? $addr['postcode'] ?? null,
                    'country' => $addr['country'] ?? $addr['countryCode'] ?? null,
                    'first_name' => $addr['firstName'] ?? $addr['first_name'] ?? $customer->firstName ?? null,
                    'last_name' => $addr['lastName'] ?? $addr['last_name'] ?? $customer->lastName ?? null,
                    'company' => $addr['company'] ?? $customer->company ?? null,
                    'phone' => $addr['phone'] ?? $customer->phone ?? null,
                ];
                // Remove null values to keep address clean
                $address = array_filter($address, function($value) {
                    return $value !== null && $value !== '';
                });
                if (!empty($address)) {
                    $customer->addresses[] = $address;
                }
            }
            
            if (!empty($customer->addresses)) {
                $customer->defaultAddress = $customer->addresses[0];
            }
        } 
        // Check if address fields are directly on the customer object (single address)
        elseif (!empty($wwsData['street']) || !empty($wwsData['address1']) || !empty($wwsData['city'])) {
            $address = [
                'address1' => $wwsData['street'] ?? $wwsData['address1'] ?? $wwsData['street1'] ?? null,
                'address2' => $wwsData['address2'] ?? $wwsData['street2'] ?? null,
                'city' => $wwsData['city'] ?? null,
                'province' => $wwsData['state'] ?? $wwsData['province'] ?? $wwsData['region'] ?? null,
                'zip' => $wwsData['postalCode'] ?? $wwsData['zip'] ?? $wwsData['postcode'] ?? null,
                'country' => $wwsData['country'] ?? $wwsData['countryCode'] ?? null,
                'first_name' => $customer->firstName ?? null,
                'last_name' => $customer->lastName ?? null,
                'company' => $customer->company ?? null,
                'phone' => $customer->phone ?? null,
            ];
            // Remove null values
            $address = array_filter($address, function($value) {
                return $value !== null && $value !== '';
            });
            if (!empty($address)) {
                $customer->addresses = [$address];
                $customer->defaultAddress = $address;
            }
        }
        
        // Additional
        $customer->notes = $wwsData['notes'] ?? null;
        
        return $customer;
    }
    
    /**
     * Convert core Customer model to WWS format
     * @param Customer $customer
     * @return array
     */
    public function fromCoreModel($customer, ?array $billingAddress = null): array
    {
        $wwsCustomer = [
            'id' => $customer->id,
            'customerNumber' => $customer->customerNumber,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'firstName' => $customer->firstName,
            'lastName' => $customer->lastName,
            'company' => $customer->company,
            'notes' => $customer->notes,
        ];
        
        // Convert addresses to WWS format
        $addresses = [];
        if (!empty($customer->addresses)) {
            foreach ($customer->addresses as $addr) {
                $wwsAddress = [
                    'street' => $addr['address1'] ?? null,
                    'address2' => $addr['address2'] ?? null,
                    'city' => $addr['city'] ?? null,
                    'state' => $addr['province'] ?? $addr['state'] ?? null,
                    'postalCode' => $addr['zip'] ?? $addr['postalCode'] ?? null,
                    'country' => $addr['country'] ?? null,
                ];
                // Remove null values
                $wwsAddress = array_filter($wwsAddress, function($value) {
                    return $value !== null && $value !== '';
                });
                if (!empty($wwsAddress)) {
                    $addresses[] = $wwsAddress;
                }
            }
        }
        // If billing address is provided separately (e.g., from order), use it
        elseif ($billingAddress) {
            $wwsAddress = [
                'street' => $billingAddress['address1'] ?? null,
                'address2' => $billingAddress['address2'] ?? null,
                'city' => $billingAddress['city'] ?? null,
                'state' => $billingAddress['province'] ?? $billingAddress['state'] ?? null,
                'postalCode' => $billingAddress['zip'] ?? $billingAddress['postal_code'] ?? null,
                'country' => $billingAddress['country'] ?? null,
            ];
            $wwsAddress = array_filter($wwsAddress, function($value) {
                return $value !== null && $value !== '';
            });
            if (!empty($wwsAddress)) {
                $addresses[] = $wwsAddress;
            }
        }
        
        if (!empty($addresses)) {
            $wwsCustomer['addresses'] = $addresses;
        }
        
        return $wwsCustomer;
    }
}

