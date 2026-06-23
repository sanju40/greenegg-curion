<?php
return [
    'up' => "
-- Providers Table
CREATE TABLE IF NOT EXISTS providers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    type ENUM('erp', 'ecommerce', 'wms') NOT NULL,
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    base_url VARCHAR(255),
    auth_method ENUM('basic', 'bearer', 'oauth', 'apikey') NOT NULL,
    auth_config TEXT COMMENT 'JSON: credentials, tokens, etc.',
    rate_limit_per_minute INT DEFAULT 60,
    rate_limit_per_hour INT DEFAULT 1000,
    pagination_style ENUM('offset_limit', 'page_size', 'cursor') DEFAULT 'offset_limit',
    supported_formats JSON COMMENT 'Array of supported formats: json, xml, etc.',
    capabilities JSON COMMENT 'Provider capability matrix',
    metadata JSON COMMENT 'Additional provider-specific config',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_status (type, status),
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provider Capabilities Table
CREATE TABLE IF NOT EXISTS provider_capabilities (
    id INT PRIMARY KEY AUTO_INCREMENT,
    provider_id INT NOT NULL,
    capability VARCHAR(50) NOT NULL,
    supported BOOLEAN DEFAULT FALSE,
    notes TEXT,
    FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
    UNIQUE KEY unique_provider_capability (provider_id, capability),
    INDEX idx_capability (capability)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Job Queue Table
CREATE TABLE IF NOT EXISTS job_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_name VARCHAR(100) NOT NULL,
    job_type ENUM('recurring', 'onetime') NOT NULL,
    provider_id INT NULL,
    payload TEXT COMMENT 'JSON',
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    scheduled_at TIMESTAMP NOT NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    error_message TEXT NULL,
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_job_name (job_name),
    INDEX idx_provider (provider_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Error Queue Table
CREATE TABLE IF NOT EXISTS error_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entity_type VARCHAR(50) NOT NULL COMMENT 'product, customer, order',
    entity_id VARCHAR(100) NULL,
    provider_id INT NULL,
    operation VARCHAR(50) NULL COMMENT 'sync, create, update, delete',
    error_type VARCHAR(50) NULL COMMENT 'api_error, validation_error, conflict_error',
    error_message TEXT NULL,
    error_data TEXT NULL COMMENT 'JSON',
    payload TEXT NULL COMMENT 'JSON: original data',
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    next_retry_at TIMESTAMP NULL,
    status ENUM('pending', 'retrying', 'failed', 'resolved') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_retry (status, next_retry_at),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_provider (provider_id),
    FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit Log Table
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NULL,
    entity_id VARCHAR(100) NULL,
    provider_id INT NULL,
    changes TEXT NULL COMMENT 'JSON: before/after',
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created (created_at),
    INDEX idx_provider (provider_id),
    FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
",
    'down' => "
-- Drop tables (foreign keys will be dropped automatically)
-- Order matters: drop dependent tables first
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS error_queue;
DROP TABLE IF EXISTS job_queue;
DROP TABLE IF EXISTS provider_capabilities;
DROP TABLE IF EXISTS providers;
"
];

