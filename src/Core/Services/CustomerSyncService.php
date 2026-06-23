<?php

namespace App\Core\Services;

use App\Core\Factory\ProviderFactory;
use App\Core\Contracts\ErpProviderInterface;
use App\Core\Contracts\EcommerceProviderInterface;
use App\Core\Models\Customer;
use App\Database\Repository\CustomerMappingRepository;
use App\Adapters\WwsRestService\CustomerAdapter as WwsCustomerAdapter;
use App\Adapters\Shopify\CustomerAdapter as ShopifyCustomerAdapter;
use App\Utils\Logger;
use App\Utils\LogHelper;

/**
 * Customer Sync Service
 * Handles bidirectional customer synchronization
 */
class CustomerSyncService
{
    private $erpProvider;
    private $ecommerceProvider;
    private $customerMappingRepository;
    private $logger;
    private $matchingRules;
    private $wwsCustomerAdapter;
    private $shopifyCustomerAdapter;

    public function __construct(
        ?ErpProviderInterface $erpProvider = null,
        ?EcommerceProviderInterface $ecommerceProvider = null
    ) {
        $this->erpProvider = $erpProvider ?? ProviderFactory::createErpProvider('wws');
        $this->ecommerceProvider = $ecommerceProvider ?? ProviderFactory::createEcommerceProvider('shopify');
        
        $this->customerMappingRepository = new CustomerMappingRepository();
        $this->logger = new Logger();
        $this->matchingRules = require BASE_PATH . '/config/customer-matching.php';
        $this->wwsCustomerAdapter = new WwsCustomerAdapter();
        $this->shopifyCustomerAdapter = new ShopifyCustomerAdapter();
    }

    /**
     * Inbound sync: Provider → Shopify
     * @param string $providerName
     * @param int|null $limit Batch size (null = use default from progress or 100)
     * @param int|null $offset Starting offset (null = resume from last position)
     * @param bool $skipExisting Skip customers that already exist in Shopify
     * @return array Statistics
     */
    public function syncFromProvider(string $providerName, ?int $limit = null, ?int $offset = null, bool $skipExisting = true): array
    {
        $synced = 0;
        $errors = 0;
        $skipped = 0;

        try {
            if (!$this->erpProvider->checkCapability('customer_search')) {
                LogHelper::error('Provider does not support customer search', [
                    'provider' => $providerName,
                ]);
                throw new \Exception("Provider {$providerName} doesn't support customer search");
            }

            // Get or create sync progress record
            $progress = $this->getSyncProgress($providerName);
            
            // Determine starting offset
            if ($offset === null) {
                $offset = $progress['last_synced_offset'] ?? 0;
            }
            
            // Determine batch size
            if ($limit === null) {
                $limit = $progress['last_batch_size'] ?? 100; // Default batch size
            }
            
            // Update progress status
            $this->updateSyncProgress($providerName, [
                'status' => 'in_progress',
                'last_synced_offset' => $offset,
            ]);

            LogHelper::info('Starting customer sync', [
                'provider' => $providerName,
                'offset' => $offset,
                'limit' => $limit,
                'skip_existing' => $skipExisting,
            ]);

            $customers = $this->erpProvider->searchCustomers('*', $offset, $limit);
            
            // WWS API may return customers in nested array structure
            // Flatten if needed: [[customer1], [customer2]] -> [customer1, customer2]
            // Also handle: [{0: customer1}, {0: customer2}] -> [customer1, customer2]
            if (!empty($customers) && is_array($customers)) {
                $flattened = [];
                foreach ($customers as $item) {
                    if (is_array($item)) {
                        // Check if it's a nested array with numeric keys (e.g., [0 => customer1])
                        if (isset($item[0]) && is_array($item[0]) && count($item) === 1) {
                            // Single nested customer: [0 => customerData]
                            $flattened[] = $item[0];
                        } elseif (isset($item[0]) && is_array($item[0])) {
                            // Multiple nested customers: [0 => customer1, 1 => customer2, ...]
                            $flattened = array_merge($flattened, $item);
                        } else {
                            // Direct customer data
                            $flattened[] = $item;
                        }
                    }
                }
                if (!empty($flattened)) {
                    $customers = $flattened;
                }
            }

            $lastProcessedId = null;
            $lastProcessedNumber = null;
            $batchCount = 0;

            foreach ($customers as $providerCustomer) {
                try {
                    $customerId = $providerCustomer['id'] ?? 'unknown';
                    $customerNumber = $providerCustomer['number'] ?? $providerCustomer['address']['number'] ?? null;
                    $email = $providerCustomer['email'] ?? 'no email';
                    
                    // Track last processed customer for progress tracking
                    $lastProcessedId = (string)$customerId;
                    $lastProcessedNumber = $customerNumber;
                    $batchCount++;
                    
                    // Check if customer already exists in Shopify (if skipExisting is enabled)
                    if ($skipExisting) {
                        $existingMapping = $this->customerMappingRepository->findByWwsCustomerId((string)$customerId);
                        if ($existingMapping) {
                            $skipped++;
                            LogHelper::debug('Customer already exists in Shopify, skipping', [
                                'wws_customer_id' => $customerId,
                                'shopify_customer_id' => $existingMapping['shopify_customer_id'] ?? 'unknown',
                            ]);
                            continue;
                        }
                        
                        // Also check by email if available
                        if ($email && $email !== 'no email') {
                            try {
                                $existing = $this->ecommerceProvider->searchCustomers($email, 1);
                                if (!empty($existing)) {
                                    $skipped++;
                                    LogHelper::debug('Customer already exists in Shopify (by email), skipping', [
                                        'wws_customer_id' => $customerId,
                                        'email' => $email,
                                    ]);
                                    // Create mapping if not exists
                                    $this->customerMappingRepository->save(
                                        $existing[0]['id'],
                                        (string)$customerId,
                                        null
                                    );
                                    continue;
                                }
                            } catch (\Exception $e) {
                                // Search failed, continue to sync
                                LogHelper::warning('Customer search failed (will try to sync anyway)', [
                                    'email' => $email,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                    
                    LogHelper::debug('Processing customer', [
                        'customer_id' => $customerId,
                        'email' => $email,
                    ]);
                    
                    $result = $this->syncToShopify($providerCustomer);
                    if ($result && !empty($result['id'])) {
                        $synced++;
                        LogHelper::info('Customer synced successfully', [
                            'wws_customer_id' => $customerId,
                            'shopify_customer_id' => $result['id'] ?? 'unknown',
                        ]);
                    } else {
                        // syncToShopify already logged the failure to database if email was missing
                        // Only increment errors if it wasn't already logged (e.g., other sync failures)
                        $errors++;
                        LogHelper::error('Customer sync failed or skipped', [
                            'customer_id' => $customerId,
                            'email' => $email,
                            'reason' => (!$email || $email === 'no email') 
                                ? 'Missing email address (logged to database)' 
                                : 'syncToShopify returned null or empty result',
                        ]);
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $customerId = $providerCustomer['id'] ?? 'unknown';
                    LogHelper::error('Exception syncing customer', [
                        'customer_id' => $customerId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
            
            // Update progress after batch
            $newOffset = $offset + $batchCount;
            $this->updateSyncProgress($providerName, [
                'last_synced_offset' => $newOffset,
                'last_synced_customer_id' => $lastProcessedId,
                'last_synced_customer_number' => $lastProcessedNumber,
                'total_processed' => ($progress['total_processed'] ?? 0) + $batchCount,
                'last_batch_size' => $batchCount,
                'status' => empty($customers) || $batchCount < $limit ? 'completed' : 'in_progress',
            ]);
        } catch (\Exception $e) {
            LogHelper::error('Failed to sync customers from provider', [
                'provider' => $providerName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception("Failed to sync customers from provider: " . $e->getMessage());
        }

        return [
            'synced' => $synced,
            'errors' => $errors,
            'skipped' => $skipped,
            'offset' => $offset,
            'next_offset' => $offset + count($customers),
            'batch_size' => count($customers),
        ];
    }

    /**
     * Outbound sync: Shopify → Provider
     * @param array $shopifyCustomer
     * @param string $providerName
     * @return array|null Created/updated customer data
     */
    public function syncToProvider(array $shopifyCustomer, string $providerName): ?array
    {
        if (!$this->erpProvider->checkCapability('customer_create')) {
            return null; // Provider doesn't support, skip
        }

        // Check if customer exists in provider
        $existing = $this->findCustomerInProvider($shopifyCustomer);

        if ($existing) {
            if ($this->erpProvider->checkCapability('customer_update')) {
                // Convert Shopify customer to core model, then to provider format
                $coreCustomer = $this->shopifyCustomerAdapter->toCoreModel($shopifyCustomer);
                $providerCustomerData = $this->wwsCustomerAdapter->fromCoreModel($coreCustomer);
                
                // Update customer in provider
                $updated = $this->erpProvider->updateCustomer($existing['id'], $providerCustomerData);
                
                // Update mapping
                $providerCustomerId = $updated['id'] ?? $existing['id'] ?? null;
                
                $this->customerMappingRepository->save(
                    $shopifyCustomer['id'],
                    $providerCustomerId,
                    $coreCustomer
                );
                
                return $updated;
            }
        } else {
            // Convert Shopify customer to core model, then to provider format
            $coreCustomer = $this->shopifyCustomerAdapter->toCoreModel($shopifyCustomer);
            $providerCustomerData = $this->wwsCustomerAdapter->fromCoreModel($coreCustomer);
            
            // Create customer in provider
            $created = $this->erpProvider->createCustomer($providerCustomerData);
            
            if ($created) {
                $providerCustomer = is_array($created) && isset($created[0]) ? $created[0] : $created;
                
                // Create mapping
                $this->customerMappingRepository->save(
                    $shopifyCustomer['id'],
                    $providerCustomer['id'] ?? null,
                    $coreCustomer
                );
                
                return $providerCustomer;
            }
        }

        return null;
    }

    /**
     * Sync provider customer to Shopify
     * @param array $providerCustomer
     * @return array|null Created/updated Shopify customer
     */
    public function syncToShopify(array $providerCustomer): ?array
    {
        // Convert provider customer to core model
        $coreCustomer = $this->wwsCustomerAdapter->toCoreModel($providerCustomer);
        
        $email = $coreCustomer->email;
        
        // If no email, skip creating customer in Shopify and log as failed
        if (!$email) {
            $customerId = $providerCustomer['id'] ?? $coreCustomer->id ?? 'unknown';
            $customerNumber = $coreCustomer->customerNumber 
                ?? $providerCustomer['number'] 
                ?? $providerCustomer['address']['number'] 
                ?? $customerId;
            
            $errorMessage = "Customer missing email address. Customer ID: {$customerId}, Number: {$customerNumber}";
            
            // Log to sync_logs with status 'failed'
            $this->logger->logSync(
                'customer_sync',
                'customer',
                (string)$customerId,
                null, // No Shopify ID since customer wasn't created
                'failed',
                $providerCustomer, // Request data
                null, // No response data
                $errorMessage
            );
            
            LogHelper::warning('Customer sync skipped: missing email', [
                'customer_id' => $customerId,
                'customer_number' => $customerNumber,
            ]);
            return null;
        }

        // Check if exists in Shopify
        // Try to search, but if it fails (e.g., 403 permission error), continue to create
        $existing = [];
        try {
            $existing = $this->ecommerceProvider->searchCustomers($email, 1);
        } catch (\Exception $e) {
            // If search fails (e.g., no permission for search endpoint), log and continue
            // We'll try to create and handle duplicate error if customer already exists
            LogHelper::warning('Customer search failed (will try to create anyway)', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
        
        if (!empty($existing)) {
            // Customer already exists in Shopify - skip update to preserve user changes
            $existingCustomer = $existing[0];
            
            // Just ensure mapping exists
            $this->customerMappingRepository->save(
                $existingCustomer['id'],
                $providerCustomer['id'] ?? null,
                $coreCustomer
            );
            
            LogHelper::debug('Customer already exists in Shopify, skipping update', [
                'wws_customer_id' => $providerCustomer['id'] ?? 'unknown',
                'shopify_customer_id' => $existingCustomer['id'],
            ]);
            
            // Return existing customer but don't update
            return $existingCustomer;
        } else {
            // Create new customer in Shopify
            // Add tags for new customers: ERP-{PROVIDER} and API_CUSTOMERS
            $erpProviderName = strtoupper($this->erpProvider->getName());
            $syncTags = [
                "ERP-{$erpProviderName}",
                "API_CUSTOMERS"
            ];
            
            // Merge with existing tags if any
            if (is_array($coreCustomer->tags) && !empty($coreCustomer->tags)) {
                $coreCustomer->tags = array_merge($coreCustomer->tags, $syncTags);
            } else {
                $coreCustomer->tags = $syncTags;
            }
            
            $shopifyCustomerData = $this->shopifyCustomerAdapter->fromCoreModel($coreCustomer);
            
            LogHelper::info('Creating new customer in Shopify', [
                'email' => $email,
                'tags' => $syncTags,
                'customer_data' => $shopifyCustomerData,
            ]);
            
            $created = $this->ecommerceProvider->createCustomer($shopifyCustomerData);
            
            if ($created && !empty($created['id'])) {
                // Create mapping
                $this->customerMappingRepository->save(
                    $created['id'],
                    $providerCustomer['id'] ?? null,
                    $coreCustomer
                );
                
                $this->logger->logSync(
                    'customer_sync',
                    'customer',
                    $providerCustomer['id'] ?? null,
                    $created['id'],
                    'success',
                    $shopifyCustomerData,
                    $created,
                    null
                );
                
                LogHelper::info('Customer created successfully in Shopify', [
                    'shopify_customer_id' => $created['id'],
                    'wws_customer_id' => $providerCustomer['id'] ?? null,
                ]);
                return $created;
            } else {
                LogHelper::error('Customer creation failed in Shopify', [
                    'wws_customer_id' => $providerCustomer['id'] ?? null,
                    'email' => $email,
                    'response' => $created,
                ]);
                // Log error to sync_logs
                $this->logger->logSync(
                    'customer_sync',
                    'customer',
                    $providerCustomer['id'] ?? null,
                    null,
                    'failed',
                    $shopifyCustomerData,
                    null,
                    'Customer creation returned null or empty response'
                );
            }
        }
        
        return null;
    }

    /**
     * Find customer in provider by email
     * @param array $shopifyCustomer
     * @return array|null
     */
    private function findCustomerInProvider(array $shopifyCustomer): ?array
    {
        $email = $shopifyCustomer['email'] ?? null;
        if (!$email || !$this->erpProvider->checkCapability('customer_search')) {
            return null;
        }

        $results = $this->erpProvider->searchCustomers($email, 0, 1);
        foreach ($results as $result) {
            if (($result['email'] ?? null) === $email) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Get sync progress for a provider
     * @param string $providerName
     * @return array|null
     */
    private function getSyncProgress(string $providerName): ?array
    {
        $db = \App\Database\Database::getInstance()->getConnection();
        
        try {
            $stmt = $db->prepare("
                SELECT * FROM customer_sync_progress 
                WHERE provider_name = ?
            ");
            $stmt->execute([$providerName]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$result) {
                // Create initial progress record
                $stmt = $db->prepare("
                    INSERT INTO customer_sync_progress 
                    (provider_name, status, last_synced_offset, total_processed)
                    VALUES (?, 'in_progress', 0, 0)
                ");
                $stmt->execute([$providerName]);
                
                return [
                    'provider_name' => $providerName,
                    'last_synced_offset' => 0,
                    'last_synced_customer_id' => null,
                    'last_synced_customer_number' => null,
                    'total_processed' => 0,
                    'last_batch_size' => null,
                    'status' => 'in_progress',
                ];
            }
            
            return $result;
        } catch (\Exception $e) {
            LogHelper::warning('Failed to get sync progress', [
                'provider_name' => $providerName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Update sync progress for a provider
     * @param string $providerName
     * @param array $data
     * @return bool
     */
    private function updateSyncProgress(string $providerName, array $data): bool
    {
        $db = \App\Database\Database::getInstance()->getConnection();
        
        try {
            $fields = [];
            $values = [];
            
            foreach ($data as $key => $value) {
                $fields[] = "{$key} = ?";
                $values[] = $value;
            }
            
            $values[] = $providerName;
            
            $stmt = $db->prepare("
                UPDATE customer_sync_progress 
                SET " . implode(', ', $fields) . "
                WHERE provider_name = ?
            ");
            
            return $stmt->execute($values);
        } catch (\Exception $e) {
            LogHelper::warning('Failed to update sync progress', [
                'provider_name' => $providerName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

