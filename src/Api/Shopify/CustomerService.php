<?php

namespace App\Api\Shopify;

/**
 * Shopify Customer Service
 * Handles Shopify customer operations
 */
class CustomerService
{
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get customer by ID
     * @param mixed $customerId
     * @return array|null
     */
    public function getCustomer($customerId)
    {
        $result = $this->client->get("customers/{$customerId}.json");
        return $result['customer'] ?? null;
    }

    /**
     * Search customers by query
     * @param string $query Search query (email, phone, name, etc.)
     * @param int $limit Maximum number of results
     * @return array Array of customers
     */
    public function searchCustomers($query, $limit = 10)
    {
        $result = $this->client->get('customers/search.json', [
            'query' => $query,
            'limit' => $limit,
        ]);
        
        return $result['customers'] ?? [];
    }

    /**
     * Get customers by email
     * @param string $email
     * @return array Array of customers
     */
    public function getCustomersByEmail($email)
    {
        $result = $this->client->get('customers.json', [
            'email' => $email,
            'limit' => 250,
        ]);
        
        return $result['customers'] ?? [];
    }

    /**
     * Create customer
     * @param array $customerData
     * @return array|null Created customer
     */
    public function createCustomer(array $customerData)
    {
        $result = $this->client->post('customers.json', ['customer' => $customerData]);
        return $result['customer'] ?? null;
    }

    /**
     * Update customer
     * @param mixed $customerId
     * @param array $customerData
     * @return array|null Updated customer
     */
    public function updateCustomer($customerId, array $customerData)
    {
        $result = $this->client->put("customers/{$customerId}.json", ['customer' => $customerData]);
        return $result['customer'] ?? null;
    }

    /**
     * Get all customers (paginated)
     * @param int $limit
     * @param int $page
     * @return array Array of customers
     */
    public function getAllCustomers($limit = 250, $page = 1)
    {
        $result = $this->client->get('customers.json', [
            'limit' => $limit,
            'page' => $page,
        ]);
        
        return $result['customers'] ?? [];
    }
}

