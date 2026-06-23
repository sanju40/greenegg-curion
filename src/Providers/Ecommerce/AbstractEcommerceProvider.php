<?php

namespace App\Providers\Ecommerce;

use App\Core\Contracts\EcommerceProviderInterface;

/**
 * Abstract E-commerce Provider
 * Base class for all e-commerce provider implementations
 */
abstract class AbstractEcommerceProvider implements EcommerceProviderInterface
{
    protected $name;
    protected $config;
    protected $capabilities = [];

    public function __construct($name, array $config, array $capabilities = [])
    {
        $this->name = $name;
        $this->config = $config;
        $this->capabilities = $capabilities;
    }

    /**
     * Get provider name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get provider configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Check if provider supports a capability
     */
    public function checkCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities);
    }

    /**
     * Set capabilities
     */
    public function setCapabilities(array $capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    /**
     * Get all capabilities
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }
}

