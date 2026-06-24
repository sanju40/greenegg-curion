<?php

namespace App\Api\WwsRestService;

/**
 * Customer Service
 * Handles customer-related API operations
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
     */
    public function getCustomer($customerId)
    {
        $databaseId = $this->client->getDatabaseId();
        $result = $this->client->get("customer/{$databaseId}/{$customerId}");
        return !empty($result) ? $result[0] : null;
    }

    /**
     * Search customers
     */
    public function searchCustomers($searchString, $offset = 0, $limit = 0)
    {
        $databaseId = $this->client->getDatabaseId();
        $endpoint = "customerSearch/{$databaseId}/{$searchString}";
        
        if ($offset > 0 || $limit > 0) {
            $endpoint .= "/{$offset}/{$limit}";
        }
        
        $result = $this->client->get($endpoint);

        // The WWS DataSnap API wraps search results in an extra array level:
        //   {"result": [[customer1, customer2, ...]]}
        // Client::get() unwraps the outer "result" key, leaving [[customer1, ...]].
        // Unwrap one more level so callers always receive [customer1, customer2, ...].
        if (isset($result[0]) && is_array($result[0])) {
            return $result[0];
        }

        return $result;
    }

    /**
     * Create new customer
     */
    public function createCustomer(array $customerData)
    {
        $databaseId = $this->client->getDatabaseId();
        return $this->client->post("customer/{$databaseId}/new", $customerData);
    }

    /**
     * Update customer
     */
    public function updateCustomer($customerId, array $customerData)
    {
        $databaseId = $this->client->getDatabaseId();
        return $this->client->post("customer/{$databaseId}/{$customerId}", $customerData);
    }
}

