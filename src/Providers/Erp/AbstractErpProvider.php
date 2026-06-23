<?php

namespace App\Providers\Erp;

use App\Core\Contracts\ErpProviderInterface;

/**
 * Abstract ERP Provider
 * Base class for all ERP provider implementations
 */
abstract class AbstractErpProvider implements ErpProviderInterface
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

