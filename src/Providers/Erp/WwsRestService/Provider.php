<?php

namespace App\Providers\Erp\WwsRestService;

use App\Providers\Erp\AbstractErpProvider;
use App\Api\WwsRestService\Client;
use App\Api\WwsRestService\ProductService;
use App\Api\WwsRestService\CustomerService;
use App\Api\WwsRestService\TransactionService;
use App\Api\WwsRestService\LookupService;
use App\Utils\LogHelper;

/**
 * WWS RestService ERP Provider
 * Wraps WWS API clients to implement ErpProviderInterface
 */
class Provider extends AbstractErpProvider
{
    private $client;
    /** @var Client Same credentials; optional WWS_ORDERS_BASE_URL / WWS_ORDERS_DATABASE_ID when set */
    private $ordersClient;
    private $productService;
    private $customerService;
    /** @var CustomerService Customer search/create against orders API when WWS_ORDERS_BASE_URL is set */
    private $ordersCustomerService;
    private $transactionService;
    private $lookupService;

    public function __construct(?array $config = null)
    {
        $config = $config ?? \App\Core\Config::get('wws', []);
        
        // Default WWS capabilities
        $capabilities = [
            'product_listing',
            'product_detail',
            'product_search',
            'inventory_read',
            'pricing_read',
            'customer_search',
            'customer_create',
            'customer_update',
            'order_create',
            'order_read',
        ];

        parent::__construct('wws', $config, $capabilities);

        $this->client = new Client(false);
        $this->ordersClient = new Client(true);

        if (!empty(trim((string) ($config['orders_base_url'] ?? '')))) {
            LogHelper::info('WWS order API: using WWS_ORDERS_BASE_URL (catalog sync uses WWS_BASE_URL)', [
                'orders_base_url' => $config['orders_base_url'],
            ]);
        }

        $this->productService = new ProductService($this->client);
        $this->customerService = new CustomerService($this->client);
        $this->ordersCustomerService = new CustomerService($this->ordersClient);
        $this->transactionService = new TransactionService($this->ordersClient);
        $this->lookupService = new LookupService($this->client);
    }

    /**
     * Get product by ID
     */
    public function getProduct($productId)
    {
        return $this->productService->getProduct($productId);
    }

    /**
     * Search products
     */
    public function searchProducts($query, $offset = 0, $limit = 0)
    {
        return $this->productService->searchProducts($query, $offset, $limit);
    }

    /**
     * Get multiple products by IDs
     */
    public function getProducts(array $productIds)
    {
        $products = [];
        foreach ($productIds as $id) {
            try {
                $product = $this->getProduct($id);
                if ($product) {
                    $products[] = $product;
                }
            } catch (\Exception $e) {
                // Log error but continue
                LogHelper::error('Failed to get product from WWS', [
                    'product_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $products;
    }

    /**
     * Get product by SKU
     */
    public function getProductBySku($sku)
    {
        // WWS search by SKU
        $results = $this->searchProducts($sku, 0, 1);
        if (!empty($results) && is_array($results)) {
            // Flatten if nested
            $products = isset($results[0]) && is_array($results[0]) && isset($results[0][0]) 
                ? $results[0] 
                : $results;
            
            foreach ($products as $product) {
                if (isset($product['sku']) && $product['sku'] === $sku) {
                    return $product;
                }
            }
        }
        return null;
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
    public function searchCustomers($query, $offset = 0, $limit = 0)
    {
        return $this->customerService->searchCustomers($query, $offset, $limit);
    }

    /**
     * Create customer (catalog / CustomerSync — always WWS_BASE_URL)
     */
    public function createCustomer(array $customerData)
    {
        return $this->customerService->createCustomer($customerData);
    }

    /**
     * Search customers on the order/staging WWS host when WWS_ORDERS_BASE_URL is set.
     * Used by order processing only; catalog sync uses searchCustomers().
     */
    public function searchCustomersForOrder($query, $offset = 0, $limit = 0)
    {
        return $this->ordersCustomerService->searchCustomers($query, $offset, $limit);
    }

    /**
     * Create customer on the order/staging WWS host when WWS_ORDERS_BASE_URL is set.
     * Used by order processing only; catalog sync uses createCustomer().
     */
    public function createCustomerForOrder(array $customerData)
    {
        return $this->ordersCustomerService->createCustomer($customerData);
    }

    /**
     * Update customer
     */
    public function updateCustomer($customerId, array $customerData)
    {
        return $this->customerService->updateCustomer($customerId, $customerData);
    }

    /**
     * Create transaction (order)
     */
    public function createTransaction(array $transactionData)
    {
        return $this->transactionService->createTransaction($transactionData);
    }

    /**
     * Get transaction by ID
     */
    public function getTransaction($transactionId)
    {
        return $this->transactionService->getTransaction($transactionId);
    }
}

