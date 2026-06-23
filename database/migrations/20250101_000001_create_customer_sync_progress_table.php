<?php
return [
    'up' => "
CREATE TABLE IF NOT EXISTS customer_sync_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_name VARCHAR(50) NOT NULL,
    last_synced_offset INT DEFAULT 0 COMMENT 'Last offset processed',
    last_synced_customer_id VARCHAR(255) NULL COMMENT 'Last customer ID processed (for variable page sizes)',
    last_synced_customer_number VARCHAR(255) NULL COMMENT 'Last customer number processed',
    total_processed INT DEFAULT 0 COMMENT 'Total customers processed so far',
    last_batch_size INT NULL COMMENT 'Size of last batch processed',
    status ENUM('in_progress', 'completed', 'paused') DEFAULT 'in_progress',
    error_message TEXT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    UNIQUE KEY unique_provider (provider_name),
    INDEX idx_status (status),
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
",
    'down' => "
DROP TABLE IF EXISTS customer_sync_progress
"
];

