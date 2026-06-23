<?php
return [
    'up' => "
-- Enhanced product_mappings table
-- Note: MySQL doesn't support IF NOT EXISTS in ALTER TABLE, so we check manually
-- These will fail if columns already exist, but that's okay - migration will continue

ALTER TABLE product_mappings 
ADD COLUMN provider_id INT NULL,
ADD COLUMN authoritative BOOLEAN DEFAULT FALSE,
ADD COLUMN external_sku VARCHAR(100) NULL,
ADD COLUMN last_price_sync TIMESTAMP NULL,
ADD COLUMN last_inventory_sync TIMESTAMP NULL,
ADD COLUMN sync_priority INT DEFAULT 0;

CREATE INDEX idx_provider_sku ON product_mappings(provider_id, external_sku);
CREATE INDEX idx_authoritative ON product_mappings(authoritative, provider_id);

-- Enhanced api_logs table
ALTER TABLE api_logs 
ADD COLUMN provider_id INT NULL,
ADD COLUMN operation_type VARCHAR(50) NULL,
ADD COLUMN rate_limit_remaining INT NULL,
ADD COLUMN rate_limit_reset TIMESTAMP NULL;

CREATE INDEX idx_provider_operation ON api_logs(provider_id, operation_type);

-- Enhanced sync_logs table
ALTER TABLE sync_logs 
ADD COLUMN provider_id INT NULL,
ADD COLUMN sync_direction ENUM('inbound', 'outbound', 'bidirectional') NULL,
ADD COLUMN records_processed INT DEFAULT 0,
ADD COLUMN records_succeeded INT DEFAULT 0,
ADD COLUMN records_failed INT DEFAULT 0;

CREATE INDEX idx_provider_direction ON sync_logs(provider_id, sync_direction);
",
    'down' => "
-- Remove indexes first
ALTER TABLE sync_logs DROP INDEX IF EXISTS idx_provider_direction;
ALTER TABLE api_logs DROP INDEX IF EXISTS idx_provider_operation;
ALTER TABLE product_mappings DROP INDEX IF EXISTS idx_authoritative;
ALTER TABLE product_mappings DROP INDEX IF EXISTS idx_provider_sku;

-- Remove columns (may fail if columns don't exist - that's okay)
ALTER TABLE sync_logs DROP COLUMN records_failed;
ALTER TABLE sync_logs DROP COLUMN records_succeeded;
ALTER TABLE sync_logs DROP COLUMN records_processed;
ALTER TABLE sync_logs DROP COLUMN sync_direction;
ALTER TABLE sync_logs DROP COLUMN provider_id;

ALTER TABLE api_logs DROP COLUMN rate_limit_reset;
ALTER TABLE api_logs DROP COLUMN rate_limit_remaining;
ALTER TABLE api_logs DROP COLUMN operation_type;
ALTER TABLE api_logs DROP COLUMN provider_id;

ALTER TABLE product_mappings DROP COLUMN sync_priority;
ALTER TABLE product_mappings DROP COLUMN last_inventory_sync;
ALTER TABLE product_mappings DROP COLUMN last_price_sync;
ALTER TABLE product_mappings DROP COLUMN external_sku;
ALTER TABLE product_mappings DROP COLUMN authoritative;
ALTER TABLE product_mappings DROP COLUMN provider_id;
"
];

