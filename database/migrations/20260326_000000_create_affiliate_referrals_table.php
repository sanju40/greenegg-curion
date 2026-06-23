<?php
return [
    'up' => "
CREATE TABLE IF NOT EXISTS affiliate_referrals (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    uppromote_referral_id   INT NOT NULL,
    shopify_order_id        VARCHAR(50) NULL,
    shopify_order_number    VARCHAR(50) NULL,
    wws_transaction_id      VARCHAR(50) NULL,
    affiliate_id            INT NULL,
    affiliate_email         VARCHAR(255) NULL,
    affiliate_first_name    VARCHAR(100) NULL,
    affiliate_last_name     VARCHAR(100) NULL,
    affiliate_company       VARCHAR(255) NULL,
    program_id              INT NULL,
    program_name            VARCHAR(255) NULL,
    commission              DECIMAL(10,2) NULL,
    commission_type         VARCHAR(100) NULL,
    coupon_applied          VARCHAR(100) NULL,
    customer_email          VARCHAR(255) NULL,
    status                  ENUM('pending','linked','failed') NOT NULL DEFAULT 'pending',
    error_message           TEXT NULL,
    raw_payload             TEXT NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_uppromote_referral_id (uppromote_referral_id),
    INDEX idx_shopify_order_id (shopify_order_id),
    INDEX idx_wws_transaction_id (wws_transaction_id),
    INDEX idx_affiliate_id (affiliate_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
",
    'down' => "DROP TABLE IF EXISTS affiliate_referrals;"
];
