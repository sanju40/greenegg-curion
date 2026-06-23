<?php
return [
    'up' => "
-- Sync Logs Table
CREATE TABLE IF NOT EXISTS sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operation_type VARCHAR(50) NOT NULL COMMENT 'product_sync, order_processing, customer_sync',
    entity_type VARCHAR(50) NOT NULL COMMENT 'product, order, customer',
    entity_id VARCHAR(255) NOT NULL COMMENT 'External ID (WwsRestService or Shopify)',
    shopify_id VARCHAR(255) NULL COMMENT 'Shopify ID if synced',
    status ENUM('pending', 'success', 'failed', 'retrying') DEFAULT 'pending',
    request_data LONGTEXT NULL COMMENT 'JSON request payload',
    response_data LONGTEXT NULL COMMENT 'JSON response payload',
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_operation_type (operation_type),
    INDEX idx_entity_type (entity_type),
    INDEX idx_status (status),
    INDEX idx_entity_id (entity_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Logs Table
CREATE TABLE IF NOT EXISTS api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_type VARCHAR(20) NOT NULL COMMENT 'wws, shopify',
    endpoint VARCHAR(500) NOT NULL,
    method VARCHAR(10) NOT NULL COMMENT 'GET, POST, PUT, DELETE',
    request_data LONGTEXT NULL COMMENT 'JSON request payload',
    response_data LONGTEXT NULL COMMENT 'JSON response payload',
    status_code INT NULL,
    error_message TEXT NULL,
    execution_time DECIMAL(10, 4) NULL COMMENT 'Execution time in seconds',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_type (api_type),
    INDEX idx_endpoint (endpoint(255)),
    INDEX idx_status_code (status_code),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product Mappings Table
CREATE TABLE IF NOT EXISTS product_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wws_product_id VARCHAR(255) NOT NULL UNIQUE,
    wws_product_sku VARCHAR(255) NULL,
    shopify_product_id VARCHAR(255) NULL,
    shopify_variant_id VARCHAR(255) NULL,
    last_synced_at TIMESTAMP NULL,
    sync_status ENUM('synced', 'pending', 'failed') DEFAULT 'pending',
    field_mapping_config TEXT NULL COMMENT 'JSON custom mapping configuration',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wws_product_id (wws_product_id),
    INDEX idx_wws_product_sku (wws_product_sku),
    INDEX idx_shopify_product_id (shopify_product_id),
    INDEX idx_sync_status (sync_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order Queue Table
CREATE TABLE IF NOT EXISTS order_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shopify_order_id VARCHAR(255) NOT NULL UNIQUE,
    shopify_order_number VARCHAR(255) NOT NULL,
    order_data TEXT NOT NULL COMMENT 'JSON full order data from Shopify',
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    wws_transaction_id VARCHAR(255) NULL,
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    INDEX idx_shopify_order_id (shopify_order_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer Mappings Table
CREATE TABLE IF NOT EXISTS customer_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shopify_customer_id VARCHAR(255) NOT NULL UNIQUE,
    wws_customer_id VARCHAR(255) NULL,
    customer_data TEXT NULL COMMENT 'JSON customer data',
    last_synced_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_shopify_customer_id (shopify_customer_id),
    INDEX idx_wws_customer_id (wws_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
",
    'down' => "
DROP TABLE IF EXISTS customer_mappings;
DROP TABLE IF EXISTS order_queue;
DROP TABLE IF EXISTS product_mappings;
DROP TABLE IF EXISTS api_logs;
DROP TABLE IF EXISTS sync_logs;
"
];

