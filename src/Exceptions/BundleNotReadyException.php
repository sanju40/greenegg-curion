<?php

namespace App\Exceptions;

/**
 * Thrown when a bundle's child products have not yet been synced to Shopify
 * and therefore cannot be resolved from product_mappings.
 *
 * This is a dependency-ordering issue, not a code error.
 * The bundle should be skipped (not counted as an error) and retried
 * after a full product sync has populated the mappings table.
 */
class BundleNotReadyException extends \RuntimeException
{
}
