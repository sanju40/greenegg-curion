<?php

namespace App\Providers\Ecommerce\Shopify;

use App\Providers\Ecommerce\AbstractEcommerceProvider;
use App\Api\Shopify\Client;
use App\Api\Shopify\ProductService;
use App\Api\Shopify\CustomerService;
use App\Api\Shopify\OrderService;
use App\Api\Shopify\MetafieldService;

/**
 * Shopify E-commerce Provider
 * Wraps Shopify API clients to implement EcommerceProviderInterface
 */
class Provider extends AbstractEcommerceProvider
{
    private $client;
    private $productService;
    private $customerService;
    private $orderService;
    private $metafieldService;

    public function __construct(?array $config = null)
    {
        $config = $config ?? \App\Core\Config::get('shopify', []);
        
        // Default Shopify capabilities
        $capabilities = [
            'product_listing',
            'product_detail',
            'product_create',
            'product_update',
            'product_search',
            'inventory_read',
            'inventory_write',
            'pricing_read',
            'pricing_write',
            'customer_search',
            'customer_create',
            'customer_update',
            'order_read',
            'webhooks_supported',
        ];

        parent::__construct('shopify', $config, $capabilities);

        $this->client = new Client();
        $this->productService = new ProductService($this->client);
        $this->customerService = new CustomerService($this->client);
        $this->orderService = new OrderService($this->client);
        $this->metafieldService = new MetafieldService($this->client);
    }

    /**
     * Get product by ID
     */
    public function getProduct($productId)
    {
        return $this->productService->getProduct($productId);
    }

    /**
     * Get product by SKU
     */
    public function getProductBySku($sku)
    {
        return $this->productService->getProductBySku($sku);
    }

    /**
     * Create product
     */
    public function createProduct(array $productData)
    {
        return $this->productService->createProduct($productData);
    }

    /**
     * Update product
     */
    public function updateProduct($productId, array $productData)
    {
        return $this->productService->updateProduct($productId, $productData);
    }

    /**
     * Update product variant
     */
    public function updateProductVariant($productId, $variantId, array $variantData)
    {
        return $this->productService->updateProductVariant($productId, $variantId, $variantData);
    }

    /**
     * Get all products (paginated)
     */
    public function getAllProducts($limit = 250, $page = 1)
    {
        return $this->productService->getAllProducts($limit, $page);
    }

    /**
     * Get customer by ID
     */
    public function getCustomer($customerId)
    {
        return $this->customerService->getCustomer($customerId);
    }

    /**
     * Search customers
     */
    public function searchCustomers($query, $limit = 10)
    {
        return $this->customerService->searchCustomers($query, $limit);
    }

    /**
     * Create customer
     */
    public function createCustomer(array $customerData)
    {
        return $this->customerService->createCustomer($customerData);
    }

    /**
     * Update customer
     */
    public function updateCustomer($customerId, array $customerData)
    {
        return $this->customerService->updateCustomer($customerId, $customerData);
    }

    /**
     * Get order by ID
     */
    public function getOrder($orderId)
    {
        return $this->orderService->getOrder($orderId);
    }

    /**
     * Get metafield service (Shopify-specific)
     */
    public function getMetafieldService()
    {
        return $this->metafieldService;
    }
}

