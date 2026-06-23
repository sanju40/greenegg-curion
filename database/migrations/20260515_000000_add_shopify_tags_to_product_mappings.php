<?php
/**
 * Migration: Add shopify_tags + shopify_tags_updated_at to product_mappings
 *
 * Stores the comma-separated tag string pulled from Shopify so the WWS → Shopify
 * product sync can merge against the live Shopify tag set instead of overwriting
 * it. shopify_tags_updated_at tracks freshness for a future products/update
 * webhook to keep this column current without re-running a full import.
 */
return [
    'up' => "
ALTER TABLE product_mappings
ADD COLUMN shopify_tags TEXT NULL COMMENT 'Comma-separated Shopify tags, pulled by ShopifyMappingImportService'
AFTER shopify_variant_id,
ADD COLUMN shopify_tags_updated_at TIMESTAMP NULL COMMENT 'When shopify_tags was last refreshed (import or webhook)'
AFTER shopify_tags
    ",

    'down' => "
ALTER TABLE product_mappings
DROP COLUMN shopify_tags_updated_at,
DROP COLUMN shopify_tags
    "
];
