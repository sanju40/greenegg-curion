<?php

namespace App\Core\Contracts;

/**
 * E-commerce Provider Interface
 * Defines contract for all e-commerce platforms (Shopify, WooCommerce, etc.)
 */
interface EcommerceProviderInterface
{
    /**
     * Get product by ID
     * @param mixed $productId
     * @return array|null Product data
     */
    public function getProduct($productId);

    /**
     * Get product by SKU
     * @param string $sku
     * @return array|null Product data
     */
    public function getProductBySku($sku);

    /**
     * Create product
     * @param array $productData
     * @return array|null Created product data
     */
    public function createProduct(array $productData);

    /**
     * Update product
     * @param mixed $productId
     * @param array $productData
     * @return array|null Updated product data
     */
    public function updateProduct($productId, array $productData);

    /**
     * Update product variant
     * @param mixed $productId
     * @param mixed $variantId
     * @param array $variantData
     * @return array|null Updated variant data
     */
    public function updateProductVariant($productId, $variantId, array $variantData);

    /**
     * Get all products (paginated)
     * @param int $limit
     * @param int $page
     * @return array Array of products
     */
    public function getAllProducts($limit = 250, $page = 1);

    /**
     * Get customer by ID
     * @param mixed $customerId
     * @return array|null Customer data
     */
    public function getCustomer($customerId);

    /**
     * Search customers
     * @param string $query
     * @param int $limit
     * @return array Array of customers
     */
    public function searchCustomers($query, $limit = 10);

    /**
     * Create customer
     * @param array $customerData
     * @return array|null Created customer data
     */
    public function createCustomer(array $customerData);

    /**
     * Update customer
     * @param mixed $customerId
     * @param array $customerData
     * @return array|null Updated customer data
     */
    public function updateCustomer($customerId, array $customerData);

    /**
     * Get order by ID
     * @param mixed $orderId
     * @return array|null Order data
     */
    public function getOrder($orderId);

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

