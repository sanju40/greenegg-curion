<?php

namespace App\Core\Contracts;

/**
 * ERP Provider Interface
 * Defines contract for all ERP providers
 */
interface ErpProviderInterface
{
    /**
     * Get product by ID
     * @param mixed $productId
     * @return array|null Raw provider data
     */
    public function getProduct($productId);

    /**
     * Search products
     * @param string $query Search query (supports wildcards)
     * @param int $offset
     * @param int $limit
     * @return array Array of products
     */
    public function searchProducts($query, $offset = 0, $limit = 0);

    /**
     * Get multiple products by IDs
     * @param array $productIds
     * @return array Array of products
     */
    public function getProducts(array $productIds);

    /**
     * Get product by SKU
     * @param string $sku
     * @return array|null Raw provider data
     */
    public function getProductBySku($sku);

    /**
     * Get customer by ID
     * @param mixed $customerId
     * @return array|null Raw provider data
     */
    public function getCustomer($customerId);

    /**
     * Search customers
     * @param string $query Search query
     * @param int $offset
     * @param int $limit
     * @return array Array of customers
     */
    public function searchCustomers($query, $offset = 0, $limit = 0);

    /**
     * Create customer
     * @param array $customerData
     * @return array Created customer data
     */
    public function createCustomer(array $customerData);

    /**
     * Update customer
     * @param mixed $customerId
     * @param array $customerData
     * @return array Updated customer data
     */
    public function updateCustomer($customerId, array $customerData);

    /**
     * Create transaction (order)
     * @param array $transactionData
     * @return array Created transaction data
     */
    public function createTransaction(array $transactionData);

    /**
     * Get transaction by ID
     * @param mixed $transactionId
     * @return array|null Transaction data
     */
    public function getTransaction($transactionId);

    /**
     * Check if provider supports a capability
     * @param string $capability
     * @return bool
     */
    public function checkCapability(string $capability): bool;

    /**
     * Get provider name/identifier
     * @return string
     */
    public function getName();

    /**
     * Get provider configuration
     * @return array
     */
    public function getConfig();
}

