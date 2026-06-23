<?php

namespace App\Core\Contracts;

/**
 * Adapter Interface
 * Converts provider-specific data to/from core models
 */
interface AdapterInterface
{
    /**
     * Convert provider data to core model
     * @param array $providerData Raw data from provider API
     * @return mixed Core model instance (Product, Customer, Order)
     */
    public function toCoreModel(array $providerData);

    /**
     * Convert core model to provider format
     * @param mixed $coreModel Core model instance
     * @return array Data in provider format
     */
    public function fromCoreModel($coreModel);
}

