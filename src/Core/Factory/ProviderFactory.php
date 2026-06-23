<?php

namespace App\Core\Factory;

use App\Providers\Erp\WwsRestService\Provider as WwsProvider;
use App\Providers\Ecommerce\Shopify\Provider as ShopifyProvider;
use App\Core\Contracts\ErpProviderInterface;
use App\Core\Contracts\EcommerceProviderInterface;
use App\Utils\LogHelper;

/**
 * Provider Factory
 * Creates provider instances
 */
class ProviderFactory
{
    /**
     * Create ERP provider
     * @param string $name Provider name (e.g., 'wws', 'sap', 'odoo')
     * @param array|null $config Optional custom config
     * @return ErpProviderInterface
     */
    public static function createErpProvider(string $name, ?array $config = null): ErpProviderInterface
    {
        switch (strtolower($name)) {
            case 'wws':
            case 'wwsrestservice':
                return new WwsProvider($config);
            
            // Future providers
            // case 'sap':
            //     return new SapProvider($config);
            // case 'odoo':
            //     return new OdooProvider($config);
            
            default:
                LogHelper::error('Unknown ERP provider requested', [
                    'provider_name' => $name,
                ]);
                throw new \InvalidArgumentException("Unknown ERP provider: {$name}");
        }
    }

    /**
     * Create e-commerce provider
     * @param string $name Provider name (e.g., 'shopify', 'woocommerce')
     * @param array|null $config Optional custom config
     * @return EcommerceProviderInterface
     */
    public static function createEcommerceProvider(string $name, ?array $config = null): EcommerceProviderInterface
    {
        switch (strtolower($name)) {
            case 'shopify':
                return new ShopifyProvider($config);
            
            // Future providers
            // case 'woocommerce':
            //     return new WooCommerceProvider($config);
            
            default:
                LogHelper::error('Unknown e-commerce provider requested', [
                    'provider_name' => $name,
                ]);
                throw new \InvalidArgumentException("Unknown e-commerce provider: {$name}");
        }
    }

    /**
     * Get available ERP providers
     * @return array
     */
    public static function getAvailableErpProviders(): array
    {
        return ['wws'];
    }

    /**
     * Get available e-commerce providers
     * @return array
     */
    public static function getAvailableEcommerceProviders(): array
    {
        return ['shopify'];
    }
}

