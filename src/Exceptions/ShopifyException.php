<?php

namespace App\Exceptions;

/**
 * Shopify API Exception
 */
class ShopifyException extends ApiException
{
    protected $shopifyErrors;

    public function __construct($message = "", $statusCode = 0, $responseData = null, $shopifyErrors = null, $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $responseData, $code, $previous);
        $this->shopifyErrors = $shopifyErrors;
    }

    public function getShopifyErrors()
    {
        return $this->shopifyErrors;
    }
}

