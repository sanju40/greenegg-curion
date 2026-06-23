<?php

/**
 * Migration: Add retry tracking fields to affiliate_referrals
 *
 * - retry_count    : number of failed attempts so far (0 = never retried)
 * - next_retry_at  : earliest timestamp the cron job should retry this record
 * - ignored status : added to ENUM so max-retry records stop being picked up by the cron
 *
 * Run: php database/migrate.php
 */

return [
    'up' => "
        ALTER TABLE affiliate_referrals
            ADD COLUMN retry_count    INT            NOT NULL DEFAULT 0    AFTER status,
            ADD COLUMN next_retry_at  TIMESTAMP      NULL                  AFTER retry_count,
            MODIFY COLUMN status ENUM('pending','linked','failed','ignored') NOT NULL DEFAULT 'pending';

        ALTER TABLE affiliate_referrals
            ADD INDEX idx_next_retry_at (next_retry_at);
    ",

    'down' => "
        ALTER TABLE affiliate_referrals
            DROP INDEX  idx_next_retry_at,
            DROP COLUMN retry_count,
            DROP COLUMN next_retry_at,
            MODIFY COLUMN status ENUM('pending','linked','failed') NOT NULL DEFAULT 'pending';
    ",
];
