<?php
/**
 * Migration: Add provider_payload column to order_queue table
 * 
 * This migration adds a column to store the transaction payload
 * that is sent to providers, allowing easy tracking of what data
 * was sent to each provider.
 */
return [
    'up' => "
ALTER TABLE order_queue 
ADD COLUMN provider_payload TEXT NULL COMMENT 'JSON payload sent to providers (keyed by provider ID)' 
AFTER order_data
    ",
    
    'down' => "
ALTER TABLE order_queue 
DROP COLUMN provider_payload
    "
];

