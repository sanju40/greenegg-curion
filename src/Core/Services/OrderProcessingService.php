<?php

namespace App\Core\Services;

use App\Core\Factory\ProviderFactory;
use App\Core\Contracts\ErpProviderInterface;
use App\Providers\Erp\WwsRestService\Provider as WwsErpProvider;
use App\Core\Contracts\EcommerceProviderInterface;
use App\Core\Routing\OrderRouter;
use App\Database\Repository\OrderQueueRepository;
use App\Database\Repository\ProductMappingRepository;
use App\Utils\Logger;
use App\Utils\LogHelper;
use App\Exceptions\SyncException;
use App\Exceptions\ApiException;

/**
 * Order Processing Service (Core)
 * Processes Shopify orders and creates transactions in ERP providers
 * Uses provider abstraction for multi-provider support
 */
class OrderProcessingService
{
    private $erpProvider;
    private $ecommerceProvider;
    private $orderRouter;
    private $orderQueueRepository;
    private $productMappingRepository;
    private $logger;
    private $config;
    private $maxRetries;
    private $curionBundleHelper;
    private bool $dryRun;

    public function __construct(
        ?ErpProviderInterface $erpProvider = null,
        ?EcommerceProviderInterface $ecommerceProvider = null,
        ?CurionBundleHelper $curionBundleHelper = null,
        ?bool $dryRun = null
    ) {
        $this->config = \App\Core\Config::get();
        $this->dryRun = $dryRun ?? (bool) ($this->config['order_processing']['dry_run'] ?? false);
        
        // Use provided providers or create defaults
        $this->erpProvider = $erpProvider ?? ProviderFactory::createErpProvider('wws');
        $this->ecommerceProvider = $ecommerceProvider ?? ProviderFactory::createEcommerceProvider('shopify');
        
        $this->orderRouter = new OrderRouter();
        $this->orderQueueRepository = new OrderQueueRepository();
        $this->productMappingRepository = new ProductMappingRepository();
        $this->curionBundleHelper = $curionBundleHelper ?? new CurionBundleHelper(
            $this->erpProvider,
            $this->productMappingRepository
        );
        $this->logger = new Logger();
        
        // Get max retries from config (default: 3)
        $this->maxRetries = (int)($this->config['order_processing']['max_retries'] ?? env('ORDER_MAX_RETRIES', 3));
    }

    /**
     * Process order from Shopify webhook
     * @param array $orderData Shopify order data
     * @return array Processed order result
     */
    public function processOrder(array $orderData): array
    {
        $shopifyOrderId = (string)$orderData['id'];
        $shopifyOrderNumber = (string)($orderData['order_number'] ?? $orderData['name'] ?? '');

        // Create sync log
        $logId = $this->logger->logSync(
            'order_processing',
            'order',
            $shopifyOrderId,
            null,
            'pending',
            $orderData
        );

        try {
            $originalLineItemCount = count($orderData['line_items'] ?? []);
            $orderData = $this->applyCurionBundleOrderLineItemFilter($orderData);
            $filteredLineItemCount = count($orderData['line_items'] ?? []);

            if ($this->dryRun) {
                LogHelper::info('Order dry-run mode — WWS/Curion API calls disabled', [
                    'shopify_order_id'     => $shopifyOrderId,
                    'shopify_order_number' => $shopifyOrderNumber,
                    'line_items_before'    => $originalLineItemCount,
                    'line_items_after'     => $filteredLineItemCount,
                ]);
            }

            // Route order to appropriate provider(s)
            $routes = $this->orderRouter->routeOrder($orderData);
            
            if (empty($routes)) {
                LogHelper::error('No valid items found in order', [
                    'shopify_order_id' => $shopifyOrderId,
                    'shopify_order_number' => $shopifyOrderNumber,
                ]);
                throw new SyncException("No valid items found in order", 'order', $shopifyOrderId);
            }

            $results = [];
            $dryRunProviderPayload = null;
            
            // Process each route (order may be split across providers)
            foreach ($routes as $providerId => $route) {
                $erpProvider = ProviderFactory::createErpProvider($providerId);
                
                // Get or create customer.
                // Galaxus orders skip search/create entirely — a fixed Curion billing
                // address (id 9983) is always used because Galaxus handles VAT itself.
                if ($this->isGalaxusOrder($orderData)) {
                    $customer = ['id' => 9983];
                    LogHelper::info('Galaxus order — using fixed billing address', [
                        'shopify_order_id' => $shopifyOrderId,
                        'address_id'       => 9983,
                    ]);
                } else {
                    $customer = $this->getOrCreateCustomer(
                        $orderData['customer'] ?? null,
                        $orderData['billing_address'] ?? null,
                        $erpProvider,
                        $orderData['shipping_address'] ?? null
                    );
                }
                
                // Map order to transaction format
                $transactionData = $this->mapOrderToTransaction(
                    $orderData,
                    $customer,
                    $route['items'],
                    $erpProvider
                );

                if ($this->dryRun) {
                    $transactionData = $this->enrichTransactionItemsWithSkus($transactionData, $route['items']);
                    if ($dryRunProviderPayload === null) {
                        $dryRunProviderPayload = [
                            '_preview' => [
                                'dry_run'              => true,
                                'shopify_order_id'     => $shopifyOrderId,
                                'shopify_order_number' => $shopifyOrderNumber,
                                'line_items_before'    => $originalLineItemCount,
                                'line_items_after'     => $filteredLineItemCount,
                                'filtered_line_items'  => $this->summarizeLineItemsForPreview($orderData['line_items'] ?? []),
                                'routed_line_items'    => $this->summarizeLineItemsForPreview($route['items']),
                                'customer'             => $this->summarizeCustomerForPreview($customer),
                            ],
                        ];
                    }
                    $dryRunProviderPayload[$providerId] = $transactionData;

                    $this->orderQueueRepository->saveProviderPayloadIfQueued($shopifyOrderId, $dryRunProviderPayload);
                    $this->logDryRunTransactionPreview($shopifyOrderId, $shopifyOrderNumber, $providerId, $dryRunProviderPayload);

                    $results[$providerId] = [
                        'provider_id'    => $providerId,
                        'transaction_id' => null,
                        'status'         => 'dry_run',
                    ];
                    continue;
                }

                $this->orderQueueRepository->mergeProviderPayload(
                    $shopifyOrderId,
                    $providerId,
                    $transactionData
                );

                // ── Send transaction to WWS/Curion (disabled when ORDER_DRY_RUN=true) ──
                try {
                    LogHelper::debug('Creating transaction in ERP', [
                        'provider_id' => $providerId,
                        'shopify_order_id' => $shopifyOrderId,
                        'transaction_data_keys' => array_keys($transactionData),
                    ]);

                    $transaction = $erpProvider->createTransaction($transactionData);
                    
                    if (!$transaction || !isset($transaction['id'])) {
                        LogHelper::error('Failed to create transaction in provider - no transaction ID returned', [
                            'provider_id' => $providerId,
                            'shopify_order_id' => $shopifyOrderId,
                            'transaction_response' => $transaction,
                        ]);
                        throw new SyncException("Failed to create transaction in provider {$providerId}: No transaction ID returned", 'order', $shopifyOrderId);
                    }
                    
                    LogHelper::info('Transaction created successfully', [
                        'provider_id' => $providerId,
                        'shopify_order_id' => $shopifyOrderId,
                        'transaction_id' => $transaction['id'],
                    ]);
                } catch (\App\Exceptions\ApiException $e) {
                    // WWS API exception - log full details
                    LogHelper::error('WWS API error creating transaction', [
                        'provider_id' => $providerId,
                        'shopify_order_id' => $shopifyOrderId,
                        'http_code' => $e->getCode(),
                        'error_message' => $e->getMessage(),
                        'error_data' => $e->getResponseData(),
                        'transaction_data' => $transactionData,
                    ]);
                    throw new SyncException("Failed to create transaction in provider {$providerId}: " . $e->getMessage(), $e->getCode(), $e);
                } catch (\Exception $e) {
                    // Generic exception
                    LogHelper::error('Exception creating transaction in provider', [
                        'provider_id' => $providerId,
                        'shopify_order_id' => $shopifyOrderId,
                        'error_message' => $e->getMessage(),
                        'error_trace' => $e->getTraceAsString(),
                        'transaction_data' => $transactionData,
                    ]);
                    throw new SyncException("Failed to create transaction in provider {$providerId}: " . $e->getMessage(), 0, $e);
                }

                $results[$providerId] = [
                    'provider_id' => $providerId,
                    'transaction_id' => $transaction['id'],
                    'status' => 'completed',
                ];
            }

            if ($this->dryRun) {
                LogHelper::info('Order dry-run complete — not sent to WWS, order left pending', [
                    'shopify_order_id'     => $shopifyOrderId,
                    'shopify_order_number' => $shopifyOrderNumber,
                    'provider_payload_col' => 'order_queue.provider_payload',
                ]);

                $this->logger->updateSyncLogStatus(
                    $logId,
                    'success',
                    $results,
                    'dry_run — not sent to WWS',
                    $shopifyOrderId
                );

                return [
                    'shopify_order_id'     => $shopifyOrderId,
                    'shopify_order_number' => $shopifyOrderNumber,
                    'order_data'           => $orderData,
                    'results'              => $results,
                    'provider_payload'     => $dryRunProviderPayload,
                    'status'               => 'dry_run',
                    'dry_run'              => true,
                ];
            }

            // Update order queue
            $primaryTransactionId = !empty($results) ? reset($results)['transaction_id'] : null;
            $this->orderQueueRepository->updateStatusByShopifyOrderId(
                $shopifyOrderId,
                'completed',
                $primaryTransactionId
            );

            // Update Shopify order with sync status and transaction ID tags
            $this->updateOrderSyncTags($shopifyOrderId, $primaryTransactionId, $results);

            $this->logger->updateSyncLogStatus(
                $logId,
                'success',
                $results,
                null,
                $shopifyOrderId
            );

            return [
                'shopify_order_id' => $shopifyOrderId,
                'shopify_order_number' => $shopifyOrderNumber,
                'order_data' => $orderData,
                'results' => $results,
                'status' => 'completed',
            ];
        } catch (\Exception $e) {
            LogHelper::error('Order processing failed', [
                'shopify_order_id' => $shopifyOrderId,
                'shopify_order_number' => $shopifyOrderNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->logger->updateSyncLogStatus(
                $logId,
                'failed',
                null,
                $e->getMessage()
            );

            // Get current retry count from order queue
            $orderQueue = $this->orderQueueRepository->getOrder($shopifyOrderId, true);
            $currentRetryCount = $orderQueue ? (int)($orderQueue['retry_count'] ?? 0) : 0;

            // Check if we should retry or mark as failed
            if ($currentRetryCount < $this->maxRetries) {
                // Calculate exponential backoff: 5min, 10min, 20min, etc.
                $retryDelayMinutes = 5 * pow(2, $currentRetryCount);
                
                // Increment retry count and keep as pending for retry
                $this->orderQueueRepository->incrementRetryCount(
                    $shopifyOrderId,
                    $retryDelayMinutes,
                    true // by Shopify order ID
                );
                
                LogHelper::warning('Order processing failed, will retry', [
                    'shopify_order_id' => $shopifyOrderId,
                    'shopify_order_number' => $shopifyOrderNumber,
                    'retry_count' => $currentRetryCount + 1,
                    'max_retries' => $this->maxRetries,
                    'next_retry_in_minutes' => $retryDelayMinutes,
                    'error' => $e->getMessage(),
                ]);
                
                // Don't throw exception - order will be retried later
                throw new SyncException(
                    "Order processing failed (retry {$currentRetryCount}/{$this->maxRetries}): " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
            } else {
                // Max retries exceeded - mark as failed permanently
                $this->orderQueueRepository->updateStatusByShopifyOrderId(
                    $shopifyOrderId,
                    'failed',
                    null,
                    $e->getMessage()
                );
                
                LogHelper::error('Order processing failed after max retries', [
                    'shopify_order_id' => $shopifyOrderId,
                    'shopify_order_number' => $shopifyOrderNumber,
                    'retry_count' => $currentRetryCount,
                    'max_retries' => $this->maxRetries,
                    'error' => $e->getMessage(),
                ]);
                
                throw $e;
            }
        }
    }

    /**
     * Collapse Curion bundle component lines before routing to WWS.
     * Uses Shopify GraphQL lineItemGroup ("Part of:" in admin) when available.
     */
    private function applyCurionBundleOrderLineItemFilter(array $orderData): array
    {
        if (!($this->config['order_processing']['skip_curion_bundle_child_line_items'] ?? true)) {
            return $orderData;
        }

        $result = $this->curionBundleHelper->filterOrderLineItems($orderData);

        if (($result['skipped'] ?? 0) > 0 || ($result['collapsed'] ?? 0) > 0) {
            LogHelper::info('Curion bundle order line items adjusted for WWS', [
                'shopify_order_id'      => $orderData['id'] ?? null,
                'shopify_order_number'  => $orderData['order_number'] ?? $orderData['name'] ?? null,
                'skipped_child_lines'   => $result['skipped'] ?? 0,
                'collapsed_bundle_groups' => $result['collapsed'] ?? 0,
                'remaining_line_items'  => count($result['order_data']['line_items'] ?? []),
            ]);
        }

        return $result['order_data'];
    }

    /**
     * Get or create customer in ERP
     * @param array|null $customerData Shopify customer data
     * @param array|null $billingAddress Billing address data
     * @param array|null $shippingAddress Shipping address data
     * @param ErpProviderInterface $erpProvider
     * @return array Customer data
     */
    private function getOrCreateCustomer(?array $customerData, ?array $billingAddress, ErpProviderInterface $erpProvider, ?array $shippingAddress = null): array
    {
        // If no customer data, use billing address
        if (!$customerData && $billingAddress) {
            $customerData = [
                'first_name' => $billingAddress['first_name'] ?? '',
                'last_name' => $billingAddress['last_name'] ?? '',
                'email' => $billingAddress['email'] ?? '',
            ];
        }

        if (!$customerData) {
            LogHelper::error('No customer or billing address data');
            throw new SyncException("No customer or billing address data", 'order', null);
        }

        // Search for existing customer by email
        $email = $customerData['email'] ?? $billingAddress['email'] ?? null;
        $existingCustomer = null;
        
        if ($email && $erpProvider->checkCapability('customer_search')) {
            try {
                $results = $erpProvider instanceof WwsErpProvider
                    ? $erpProvider->searchCustomersForOrder($email, 0, 1)
                    : $erpProvider->searchCustomers($email, 0, 1);
                if (!empty($results) && is_array($results)) {
                    foreach ($results as $result) {
                        // WWS API nests the email inside address.email; also check top-level as fallback
                        $resultEmail = $result['email'] ?? $result['address']['email'] ?? null;
                        if ($resultEmail && strtolower($resultEmail) === strtolower($email)) {
                            $existingCustomer = $result;
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Continue to create new customer
                LogHelper::warning('Customer search failed during order processing', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If customer exists, check and handle address reuse
        if ($existingCustomer && $erpProvider->getName() === 'wws') {
            return $this->handleExistingCustomerAddress($existingCustomer, $billingAddress, $shippingAddress, $erpProvider);
        }

        // Create new customer
        if (!$erpProvider->checkCapability('customer_create')) {
            LogHelper::error('Provider does not support customer creation', [
                'provider' => $erpProvider->getName(),
            ]);
            throw new SyncException("Provider does not support customer creation", 'customer', null);
        }

        $erpCustomerData = $this->mapCustomerToErpFormat($customerData, $billingAddress, $erpProvider, $shippingAddress ?? null);

        if ($this->dryRun) {
            LogHelper::info('Order dry-run — customer create skipped (preview only)', [
                'email'            => $email,
                'customer_payload' => $erpCustomerData,
            ]);
            return [
                'id'       => 0,
                'email'    => $email,
                '_dry_run' => true,
                '_would_create' => $erpCustomerData,
            ];
        }

        $result = $erpProvider instanceof WwsErpProvider
            ? $erpProvider->createCustomerForOrder($erpCustomerData)
            : $erpProvider->createCustomer($erpCustomerData);
        
        if (empty($result) || !is_array($result)) {
            LogHelper::error('Failed to create customer', [
                'email' => $email ?? null,
            ]);
            throw new SyncException("Failed to create customer", 'customer', null);
        }

        return is_array($result) && isset($result[0]) ? $result[0] : $result;
    }

    /**
     * Handle address reuse for existing customer
     * @param array $existingCustomer Existing customer data from ERP
     * @param array|null $billingAddress Billing address from order
     * @param array|null $shippingAddress Shipping address from order
     * @param ErpProviderInterface $erpProvider
     * @return array Customer data with appropriate address
     */
    private function handleExistingCustomerAddress(array $existingCustomer, ?array $billingAddress, ?array $shippingAddress, ErpProviderInterface $erpProvider): array
    {
        // Use shipping address if available, otherwise billing address
        $orderAddress = $shippingAddress ?? $billingAddress;
        
        if (!$orderAddress) {
            // No address to compare, return existing customer
            return $existingCustomer;
        }

        // Get existing customer addresses
        $existingAddresses = [];
        if (isset($existingCustomer['address']) && is_array($existingCustomer['address'])) {
            $existingAddresses[] = $existingCustomer['address'];
        } elseif (isset($existingCustomer['addresses']) && is_array($existingCustomer['addresses'])) {
            $existingAddresses = $existingCustomer['addresses'];
        }

        // Normalize address for comparison
        $normalizeAddress = function($addr) {
            return [
                'street' => strtolower(trim($addr['street'] ?? $addr['address1'] ?? '')),
                'postalCode' => strtolower(trim($addr['postalCode'] ?? $addr['zip'] ?? '')),
                'place' => strtolower(trim($addr['place'] ?? $addr['city'] ?? '')),
                'country' => strtolower(trim(is_array($addr['country'] ?? null) ? ($addr['country']['code'] ?? $addr['country']['id'] ?? '') : ($addr['country'] ?? ''))),
            ];
        };

        $orderAddressNormalized = [
            'street' => strtolower(trim($orderAddress['address1'] ?? '')),
            'postalCode' => strtolower(trim($orderAddress['zip'] ?? '')),
            'place' => strtolower(trim($orderAddress['city'] ?? '')),
            'country' => strtolower(trim($orderAddress['country_code'] ?? $orderAddress['country'] ?? '')),
        ];

        // Check if address already exists
        foreach ($existingAddresses as $existingAddr) {
            $existingAddrNormalized = $normalizeAddress($existingAddr);
            
            if ($existingAddrNormalized['street'] === $orderAddressNormalized['street'] &&
                $existingAddrNormalized['postalCode'] === $orderAddressNormalized['postalCode'] &&
                $existingAddrNormalized['place'] === $orderAddressNormalized['place'] &&
                $existingAddrNormalized['country'] === $orderAddressNormalized['country']) {
                // Address matches - reuse existing customer
                LogHelper::info('Reusing existing customer address', [
                    'customer_id' => $existingCustomer['id'] ?? null,
                    'address_number' => $existingAddr['number'] ?? null,
                ]);
                return $existingCustomer;
            }
        }

        // Address is different - create new sub-address
        // For now, return existing customer (address creation will be handled by WWS API)
        // The address number will be set to 0 for base address in mapCustomerToWwsFormat
        LogHelper::info('Address differs from existing customer, will create sub-address', [
            'customer_id' => $existingCustomer['id'] ?? null,
        ]);
        
        return $existingCustomer;
    }

    /**
     * Map customer data to ERP format
     * @param array $customerData
     * @param array|null $billingAddress
     * @param ErpProviderInterface $erpProvider
     * @param array|null $shippingAddress Optional shipping address
     * @return array
     */
    private function mapCustomerToErpFormat(array $customerData, ?array $billingAddress, ErpProviderInterface $erpProvider, ?array $shippingAddress = null): array
    {
        // Provider-specific mapping
        // For WWS, use the existing mapping logic
        if ($erpProvider->getName() === 'wws') {
            return $this->mapCustomerToWwsFormat($customerData, $billingAddress, $shippingAddress);
        }
        
        // Generic mapping for other providers
        $address = $shippingAddress ?? $billingAddress ?? [];
        
        return [
            'first_name' => $customerData['first_name'] ?? $address['first_name'] ?? '',
            'last_name' => $customerData['last_name'] ?? $address['last_name'] ?? '',
            'email' => $customerData['email'] ?? $address['email'] ?? '',
            'phone' => $address['phone'] ?? $customerData['phone'] ?? '',
            'address1' => $address['address1'] ?? '',
            'address2' => $address['address2'] ?? '',
            'city' => $address['city'] ?? '',
            'zip' => $address['zip'] ?? '',
            'country' => $address['country_code'] ?? '',
        ];
    }

    /**
     * Map customer to WWS format (provider-specific)
     * @param array $customerData
     * @param array|null $billingAddress
     * @param array|null $shippingAddress Optional shipping address
     * @return array
     */
    private function mapCustomerToWwsFormat(array $customerData, ?array $billingAddress, ?array $shippingAddress = null): array
    {
        // Use shipping address if available, otherwise billing address
        $address = $shippingAddress ?? $billingAddress ?? [];
        
        // Get default salutation (should be looked up from lookupData)
        $salutationId = 42; // Default
        
        return [
            'address' => [
                //'salutation' => ['id' => $salutationId],
                'firstName' => $customerData['first_name'] ?? $address['first_name'] ?? '',
                'lastName' => $customerData['last_name'] ?? $address['last_name'] ?? '',
                'street' => $address['address1'] ?? '',
                'postalCode' => $address['zip'] ?? '',
                'place' => $address['city'] ?? '',
                'country' => ['id' => $this->getCountryId($address['country_code'] ?? $address['country'] ?? 'CH')],
                'phone1' => '',
                'phoneMobile' => $address['phone'] ?? $customerData['phone'] ?? '',
                'email' => $customerData['email'] ?? $address['email'] ?? '',
                'number' => 0, // Base address number is 0 (not 1)
            ],
        ];
    }

    /**
     * Map order to transaction format
     * @param array $orderData Shopify order data
     * @param array $customer ERP customer data
     * @param array $items Line items for this route
     * @param ErpProviderInterface $erpProvider
     * @return array Transaction data
     */
    private function mapOrderToTransaction(array $orderData, array $customer, array $items, ErpProviderInterface $erpProvider): array
    {
        // Provider-specific mapping
        if ($erpProvider->getName() === 'wws') {
            return $this->mapOrderToWwsTransaction($orderData, $customer, $items);
        }
        
        // Generic mapping for other providers
        $transactionItems = [];
        foreach ($items as $lineItem) {
            $product = $this->getProductForLineItem($lineItem);
            if (!$product) {
                continue;
            }

            $transactionItems[] = [
                'quantity' => $lineItem['quantity'],
                'price' => $lineItem['price'],
                'product' => $product,
            ];
        }

        if (empty($transactionItems)) {
            LogHelper::error('No valid items in order', [
                'order_id' => $orderData['id'] ?? null,
            ]);
            throw new SyncException("No valid items in order", 'order', $orderData['id']);
        }

        return [
            'order_number' => $orderData['order_number'] ?? $orderData['name'],
            'customer_id' => $customer['id'],
            'items' => $transactionItems,
            'shipping_cost' => (float)($orderData['total_shipping_price_set']['shop_money']['amount'] ?? 0),
        ];
    }

    /**
     * Map order to WWS transaction format (provider-specific)
     * @param array $orderData
     * @param array $customer
     * @param array $items
     * @return array
     */
    private function mapOrderToWwsTransaction(array $orderData, array $customer, array $items): array
    {
        // Get transaction type (order = 102 based on API docs)
        $transactionTypeId = 2; // Should be configurable
        
        // Get order number prefix from config
        $orderNumberPrefix = $this->config['order_processing']['order_number_prefix'] ?? 'BGES-';
        $orderNumber = $orderData['order_number'] ?? $orderData['name'] ?? '';
        
        // Add prefix if not already present
        if (!empty($orderNumber) && strpos($orderNumber, $orderNumberPrefix) !== 0) {
            $orderNumber = $orderNumberPrefix . $orderNumber;
        }
        
        // Get price format config for product prices
        $pricesWithoutVat = $this->config['order_processing']['prices_without_vat'] ?? true;
        
        // Get price format config for shipping prices (separate flag)
        $shippingPricesWithoutVat = $this->config['order_processing']['shipping_prices_without_vat'] ?? true;
        
        $transactionItems = [];
        foreach ($items as $lineItem) {
            $product = $this->getProductForLineItem($lineItem);
            if (!$product) {
                continue;
            }

            // Get price without VAT from Shopify
            // Shopify line_item['price'] is already without tax
            // If price_set is available, use shop_money.amount (without tax)
            $price = (float)($lineItem['price'] ?? 0);
            if ($pricesWithoutVat && isset($lineItem['price_set']['shop_money']['amount'])) {
                $price = (float)$lineItem['price_set']['shop_money']['amount'];
            }

            $transactionItems[] = [
                'quantity' => $lineItem['quantity'],
                'price' => $price,
                'product' => $product,
            ];
        }

        if (empty($transactionItems)) {
            LogHelper::error('No valid items in order (WWS)', [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? $orderData['name'] ?? null,
            ]);
            throw new SyncException("No valid items in order", 'order', $orderData['id']);
        }

        // Get shipping costs
        // Use separate flag for shipping prices
        $shippingCosts = 0.0;
        if ($shippingPricesWithoutVat) {
            // Get shipping costs without VAT from Shopify
            // Shopify total_shipping_price_set.shop_money.amount is already without tax
            if (isset($orderData['total_shipping_price_set']['shop_money']['amount'])) {
                $shippingCosts = (float)$orderData['total_shipping_price_set']['shop_money']['amount'];
            } elseif (isset($orderData['shipping_lines'][0]['price_set']['shop_money']['amount'])) {
                $shippingCosts = (float)$orderData['shipping_lines'][0]['price_set']['shop_money']['amount'];
            }
        } else {
            // Get shipping costs with VAT (if flag is false)
            // Use presentment_money which includes tax, or calculate from total
            if (isset($orderData['total_shipping_price_set']['presentment_money']['amount'])) {
                $shippingCosts = (float)$orderData['total_shipping_price_set']['presentment_money']['amount'];
            } elseif (isset($orderData['shipping_lines'][0]['price'])) {
                $shippingCosts = (float)$orderData['shipping_lines'][0]['price'];
            }
        }

        // Get packaging costs (if available in order data)
        // For now, set to 0 as Shopify doesn't provide this separately
        $packagingCosts = 0.0;

        // Detect payment method from Shopify's payment_gateway_names array.
        // TWINT check is case-insensitive; anything else is treated as card payment.
        $gatewayNames = array_map('strtolower', $orderData['payment_gateway_names'] ?? []);
        $isTwint      = in_array('twint', $gatewayNames, true);

        // Galaxus orders use fixed billing, faktArt, and termsOfPayment values
        // regardless of payment gateway (Galaxus pays VAT instead of Big Green Egg).
        if ($this->isGalaxusOrder($orderData)) {
            $faktArt        = ['id' => 142];  // "Galaxus"
            $termsOfPayment = ['id' => 341];  // "bereits bezahlt"
            $address        = ['id' => 9983]; // Fixed Galaxus billing address in Curion
        } else {
            $faktArt        = ['id' => $isTwint ? 122 : 44];
            $termsOfPayment = ['id' => $isTwint ? 181 : 61];
            $address        = ['id' => $customer['id']];
        }

        return [
            'transactionType'  => ['id' => $transactionTypeId],
            'orderNumber'      => $orderNumber,
            // shopId identifies the originating shop — always "BGE" on the initial send.
            // Affiliate orders have this overwritten to the partner name by
            // AffiliateReferralService::updateWwsTransactionShopId() when the
            // UpPromote referral.new webhook arrives.
            'shopId'           => 'BGE',
            'faktArt'          => $faktArt,
            'termsOfPayment'   => $termsOfPayment,
            'address'          => $address,
            'shippingAddress'  => $this->mapShopifyAddressToWws($orderData['shipping_address'] ?? null),
            'shippingMethod'   => $this->getShippingMethod($orderData['shipping_lines'] ?? []),
            'shippingCosts'    => $shippingCosts,
            'packagingCosts'   => $packagingCosts,
            'items'            => $transactionItems,
        ];
    }

    /**
     * Get product identifier for line item
     * @param array $lineItem
     * @return array|null Product identifier
     */
    private function getProductForLineItem(array $lineItem): ?array
    {
        $sku = $lineItem['sku'] ?? null;
        if (!$sku) {
            return null;
        }

        // Try to find mapping by SKU
        $mapping = $this->productMappingRepository->findBySku($sku);
        if ($mapping && $mapping['wws_product_id']) {
            return ['id' => $mapping['wws_product_id']];
        }

        // Fallback to SKU
        return ['sku' => $sku];
    }

    /**
     * Get country ID (simplified - should lookup from master data)
     * @param string $countryCode
     * @return int
     */
    private function getCountryId(string $countryCode): int
    {
        // Default to Switzerland (1) - should be looked up from lookupData
        $countryMap = [
            'CH' => 1,
            'DE' => 2,
            'AT' => 3,
            // Add more as needed
        ];
        
        return $countryMap[strtoupper($countryCode)] ?? 1;
    }

    /**
     * Detect whether a Shopify order originated from Galaxus.
     * Galaxus orders carry a "Galaxus" tag added by the Galaxus sales channel app.
     */
    private function isGalaxusOrder(array $orderData): bool
    {
        $rawTags = $orderData['tags'] ?? '';
        if (empty($rawTags)) {
            return false;
        }
        $tags = array_map('trim', explode(',', $rawTags));
        foreach ($tags as $tag) {
            if (strcasecmp($tag, 'Galaxus') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map a Shopify address to the WWS shippingAddress format
     * Returns null when no address is provided so the field can be omitted cleanly.
     */
    private function mapShopifyAddressToWws(?array $addr): ?array
    {
        if (!$addr) {
            return null;
        }

        return [
            'firstName'  => $addr['first_name'] ?? '',
            'lastName'   => $addr['last_name']  ?? '',
            'street'     => $addr['address1']   ?? '',
            'postalCode' => $addr['zip']        ?? '',
            'place'      => $addr['city']       ?? '',
            'country'    => ['id' => $this->getCountryId($addr['country_code'] ?? $addr['country'] ?? 'CH')],
        ];
    }

    /**
     * Get shipping method from Shopify shipping lines
     * Handles single or multiple shipping methods
     * @param array $shippingLines Array of shipping lines from Shopify order
     * @return array|string Returns array with 'id' key if using IDs, or string if using string format
     */
    private function getShippingMethod(array $shippingLines)
    {
        $defaultShippingMethodId = (int)($this->config['order_processing']['default_shipping_method_id'] ?? 1);
        $shippingMethodMapping = $this->config['order_processing']['shipping_method_mapping'] ?? [];
        $useStringFormat = $this->config['order_processing']['shipping_method_as_string'] ?? false;
        
        // Debug log to verify config
        LogHelper::debug('Shipping method config', [
            'default_id' => $defaultShippingMethodId,
            'use_string_format' => $useStringFormat,
            'mapping_count' => count($shippingMethodMapping),
        ]);
        
        // If no shipping lines, use default
        if (empty($shippingLines)) {
            if ($useStringFormat) {
                LogHelper::debug('No shipping lines provided, using default shipping method as string', [
                    'default' => (string)$defaultShippingMethodId,
                ]);
                return (string)$defaultShippingMethodId;
            } else {
                LogHelper::debug('No shipping lines provided, using default shipping method', [
                    'default_id' => $defaultShippingMethodId,
                ]);
                // Ensure ID is always an integer
                return ['id' => (int)$defaultShippingMethodId];
            }
        }
        
        // Extract all shipping titles
        $shippingTitles = [];
        foreach ($shippingLines as $line) {
            $title = $line['title'] ?? $line['code'] ?? null;
            if (!empty($title)) {
                $shippingTitles[] = trim($title);
            }
        }
        
        // If multiple shipping methods, merge with comma and skip mapping
        if (count($shippingTitles) > 1) {
            $mergedTitles = implode(', ', $shippingTitles);
            LogHelper::debug('Multiple shipping methods found, merging without mapping', [
                'shipping_titles' => $shippingTitles,
                'merged' => $mergedTitles,
            ]);
            
            // For multiple shipping methods, always return as string (mapping not applicable)
            return $mergedTitles;
        }
        
        // Single shipping method - apply mapping logic
        $shippingTitleOrCode = !empty($shippingTitles) ? $shippingTitles[0] : null;
        
        if (empty($shippingTitleOrCode)) {
            if ($useStringFormat) {
                LogHelper::debug('No shipping title/code provided, using default shipping method as string', [
                    'default' => (string)$defaultShippingMethodId,
                ]);
                return (string)$defaultShippingMethodId;
            } else {
                LogHelper::debug('No shipping title/code provided, using default shipping method', [
                    'default_id' => $defaultShippingMethodId,
                ]);
                // Ensure ID is always an integer
                return ['id' => (int)$defaultShippingMethodId];
            }
        }
        
        // Normalize shipping title/code (lowercase, trim)
        $normalizedTitle = strtolower(trim($shippingTitleOrCode));
        
        // Check if we have a mapping for this shipping title/code
        if (isset($shippingMethodMapping[$normalizedTitle])) {
            $methodValue = $shippingMethodMapping[$normalizedTitle];
            
            if ($useStringFormat) {
                LogHelper::debug('Shipping method mapped from Shopify title (string format)', [
                    'shopify_title' => $shippingTitleOrCode,
                    'wws_method' => $methodValue,
                ]);
                return (string)$methodValue;
            } else {
                LogHelper::debug('Shipping method mapped from Shopify title', [
                    'shopify_title' => $shippingTitleOrCode,
                    'wws_method_id' => $methodValue,
                ]);
                // Ensure ID is always an integer
                return ['id' => (int)$methodValue];
            }
        }
        
        // Try partial matching (e.g., "Premium Shipping" matches "premium")
        foreach ($shippingMethodMapping as $mappedTitle => $methodValue) {
            if (strpos($normalizedTitle, $mappedTitle) !== false || strpos($mappedTitle, $normalizedTitle) !== false) {
                if ($useStringFormat) {
                    LogHelper::debug('Shipping method matched via partial match (string format)', [
                        'shopify_title' => $shippingTitleOrCode,
                        'matched_title' => $mappedTitle,
                        'wws_method' => $methodValue,
                    ]);
                    return (string)$methodValue;
                } else {
                    LogHelper::debug('Shipping method matched via partial match', [
                        'shopify_title' => $shippingTitleOrCode,
                        'matched_title' => $mappedTitle,
                        'wws_method_id' => $methodValue,
                    ]);
                    // Ensure ID is always an integer
                    return ['id' => (int)$methodValue];
                }
            }
        }
        
        // No mapping found, use default
        // Always return as object with ID when not using string format (default behavior)
        if ($useStringFormat) {
            LogHelper::warning('Shipping title not found in mapping, using default as string', [
                'shopify_title' => $shippingTitleOrCode,
                'default' => (string)$defaultShippingMethodId,
                'available_mappings' => array_keys($shippingMethodMapping),
            ]);
            return (string)$defaultShippingMethodId;
        } else {
            LogHelper::warning('Shipping title not found in mapping, using default', [
                'shopify_title' => $shippingTitleOrCode,
                'default_id' => $defaultShippingMethodId,
                'available_mappings' => array_keys($shippingMethodMapping),
                'use_string_format' => $useStringFormat,
            ]);
            // Ensure we return an object with 'id' key, not a string
            return ['id' => (int)$defaultShippingMethodId];
        }
    }

    /**
     * Update Shopify order tags with sync status and transaction ID
     * @param string $shopifyOrderId
     * @param string|null $transactionId Primary transaction ID
     * @param array $results All provider results
     */
    private function updateOrderSyncTags(string $shopifyOrderId, ?string $transactionId, array $results): void
    {
        try {
            if ($this->ecommerceProvider->getName() !== 'shopify') {
                return;
            }

            // Get current order to retrieve existing tags
            $currentOrder = $this->ecommerceProvider->getOrder($shopifyOrderId);
            if (!$currentOrder) {
                LogHelper::warning('Order not found for tag update', [
                    'shopify_order_id' => $shopifyOrderId,
                ]);
                return;
            }

            // Get existing tags
            $existingTags = [];
            if (!empty($currentOrder['tags'])) {
                $existingTags = array_map('trim', explode(',', $currentOrder['tags']));
            }

            // Remove old sync-related tags
            $tagsToRemove = ['ERP_SYNCED', 'ERP_SYNC_PENDING', 'ERP_SYNC_FAILED'];
            foreach ($tagsToRemove as $tag) {
                $key = array_search($tag, $existingTags);
                if ($key !== false) {
                    unset($existingTags[$key]);
                }
            }

            // Remove old transaction ID tags (format: TRANSACTION_ID:XXXX)
            foreach ($existingTags as $key => $tag) {
                if (strpos($tag, 'TRANSACTION_ID:') === 0) {
                    unset($existingTags[$key]);
                }
            }

            // Add new sync tags
            $newTags = array_values($existingTags); // Re-index array
            
            // Add sync status tag
            $newTags[] = 'ERP_SYNCED';
            
            // Add transaction ID tag
            if ($transactionId) {
                $newTags[] = "TRANSACTION_ID:{$transactionId}";
            }

            // Add provider-specific tags if multiple providers
            if (count($results) > 1) {
                foreach ($results as $providerId => $result) {
                    if (isset($result['transaction_id'])) {
                        $providerTag = strtoupper($providerId) . '_TXN:' . $result['transaction_id'];
                        $newTags[] = $providerTag;
                    }
                }
            }

            // ── Note attributes ───────────────────────────────────────────
            // Preserve existing attributes, replace only ERP-owned keys
            $erpAttrKeys    = ['Transaction ID', 'ERP Sync Status'];
            $existingAttrs  = $currentOrder['note_attributes'] ?? [];
            $noteAttributes = array_values(array_filter(
                $existingAttrs,
                fn($attr) => !in_array($attr['name'] ?? '', $erpAttrKeys, true)
            ));

            $noteAttributes[] = ['name' => 'ERP Sync Status', 'value' => 'Synced'];
            if ($transactionId) {
                $noteAttributes[] = ['name' => 'Transaction ID', 'value' => (string)$transactionId];
            }

            // Update order with new tags and note_attributes in a single call
            $orderService = new \App\Api\Shopify\OrderService();
            $orderService->updateOrder($shopifyOrderId, [
                'tags'            => implode(', ', $newTags),
                'note_attributes' => $noteAttributes,
            ]);

            LogHelper::info('Order sync tags updated', [
                'shopify_order_id' => $shopifyOrderId,
                'transaction_id'   => $transactionId,
                'tags'             => $newTags,
                'note_attributes'  => $noteAttributes,
            ]);
        } catch (\Exception $e) {
            // Don't fail order processing if tag update fails
            LogHelper::warning('Failed to update order sync tags', [
                'shopify_order_id' => $shopifyOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Add Shopify SKU next to WWS product id in dry-run payloads (not sent to WWS API).
     */
    private function enrichTransactionItemsWithSkus(array $transactionData, array $lineItems): array
    {
        if (empty($transactionData['items']) || !is_array($transactionData['items'])) {
            return $transactionData;
        }

        $skus = [];
        $childItems = [];
        foreach ($lineItems as $lineItem) {
            if (!$this->getProductForLineItem($lineItem)) {
                continue;
            }
            $sku = trim((string) ($lineItem['sku'] ?? ''));
            $skus[] = $sku !== '' ? $sku : null;

            $bundleChildren = $lineItem['curion_bundle_children'] ?? null;
            $childItems[] = (is_array($bundleChildren) && $bundleChildren !== []) ? $bundleChildren : null;
        }

        foreach ($transactionData['items'] as $i => &$item) {
            if (!isset($item['product']) || !is_array($item['product'])) {
                continue;
            }
            if (!empty($skus[$i])) {
                $item['product']['sku'] = $skus[$i];
            }
            if (!empty($childItems[$i])) {
                $item['product']['child_items'] = $childItems[$i];
                $item['product']['child_skus'] = array_values(array_map(
                    static fn (array $child) => $child['sku'],
                    $childItems[$i]
                ));
            }
        }
        unset($item);

        return $transactionData;
    }

    /**
     * Summarize line items for dry-run preview logs / provider_payload._preview
     */
    private function summarizeLineItemsForPreview(array $lineItems): array
    {
        $summary = [];
        foreach ($lineItems as $item) {
            $entry = [
                'id'       => $item['id'] ?? null,
                'sku'      => $item['sku'] ?? null,
                'title'    => $item['title'] ?? $item['name'] ?? null,
                'quantity' => $item['quantity'] ?? null,
                'price'    => $item['price'] ?? null,
                'total'    => isset($item['price'], $item['quantity'])
                    ? (float) $item['price'] * (float) $item['quantity']
                    : null,
                'product_id' => $item['product_id'] ?? null,
                'variant_id' => $item['variant_id'] ?? null,
            ];
            if (!empty($item['curion_bundle_children'])) {
                $entry['child_items'] = $item['curion_bundle_children'];
            }
            $summary[] = $entry;
        }
        return $summary;
    }

    /**
     * Summarize customer for dry-run preview (omit large nested blobs)
     */
    private function summarizeCustomerForPreview(array $customer): array
    {
        $summary = [
            'id'    => $customer['id'] ?? null,
            'email' => $customer['email'] ?? null,
        ];
        if (!empty($customer['_dry_run'])) {
            $summary['dry_run'] = true;
            $summary['would_create'] = $customer['_would_create'] ?? null;
        }
        return $summary;
    }

    /**
     * Log full WWS transaction payload during dry-run (inspect logs or order_queue.provider_payload)
     */
    private function logDryRunTransactionPreview(
        string $shopifyOrderId,
        string $shopifyOrderNumber,
        string $providerId,
        array $previewPayload
    ): void {
        LogHelper::info('Order dry-run — WWS transaction payload preview', [
            'shopify_order_id'     => $shopifyOrderId,
            'shopify_order_number' => $shopifyOrderNumber,
            'provider_id'          => $providerId,
            'preview'              => $previewPayload['_preview'] ?? null,
            'transaction_payload'  => $previewPayload[$providerId] ?? null,
        ]);
    }
}

