<?php

/**
 * CLI: Sync Single Customer
 * Usage: 
 *   php cli/sync-customer.php --id=123 --provider=wws
 *   php cli/sync-customer.php --email=user@example.com --provider=wws
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Core\Config;
use App\Core\Services\CustomerSyncService;
use App\Core\Factory\ProviderFactory;

$config = Config::get();

if (!$config['cli_enabled']) {
    echo "CLI commands are disabled\n";
    exit(1);
}

// Parse command line arguments
$options = getopt('', ['id:', 'email:', 'provider:']);
$providerName = $options['provider'] ?? 'wws';
$customerId = $options['id'] ?? null;
$email = $options['email'] ?? null;

if (!$customerId && !$email) {
    echo "Error: Either --id or --email is required\n";
    echo "Usage: php cli/sync-customer.php --id=123 --provider=wws\n";
    echo "   or: php cli/sync-customer.php --email=user@example.com --provider=wws\n";
    exit(1);
}

echo "Syncing customer...\n";
echo "Provider: {$providerName}\n";
if ($customerId) {
    echo "Customer ID: {$customerId}\n";
} else {
    echo "Email: {$email}\n";
}

try {
    $erpProvider = ProviderFactory::createErpProvider($providerName);
    $syncService = new CustomerSyncService($erpProvider);
    
    // Get customer from provider
    if ($customerId) {
        $customer = $erpProvider->getCustomer($customerId);
    } else {
        $results = $erpProvider->searchCustomers($email, 0, 1);
        if (empty($results)) {
            \App\Utils\LogHelper::error('Customer not found with email', [
                'email' => $email,
                'provider' => $providerName,
            ]);
            throw new \Exception("Customer not found with email: {$email}");
        }
        $customer = $results[0];
    }
    
    if (!$customer) {
        \App\Utils\LogHelper::error('Customer not found', [
            'customer_id' => $customerId ?? null,
            'provider' => $providerName,
        ]);
        throw new \Exception("Customer not found");
    }
    
    // Sync to Shopify (now public method)
    $result = $syncService->syncToShopify($customer);
    
    if ($result) {
        echo "\nCustomer synced successfully!\n";
        echo "Shopify Customer ID: {$result['id']}\n";
        echo "Email: " . ($result['email'] ?? 'N/A') . "\n";
        exit(0);
    } else {
        echo "\nCustomer sync failed (no result returned)\n";
        exit(1);
    }
} catch (\Exception $e) {
    \App\Utils\LogHelper::critical('Customer sync failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

