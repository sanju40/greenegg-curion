<?php
return [
    'up' => "
-- Application Logs Table
-- Stores all application-level logs with different severity levels
CREATE TABLE IF NOT EXISTS application_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL COMMENT 'EMERGENCY, ALERT, CRITICAL, ERROR, WARNING, NOTICE, INFO, DEBUG',
    message TEXT NOT NULL,
    context LONGTEXT NULL COMMENT 'JSON context data',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (level),
    INDEX idx_created_at (created_at),
    INDEX idx_level_created (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
",
    'down' => "
DROP TABLE IF EXISTS application_logs;
"
];

