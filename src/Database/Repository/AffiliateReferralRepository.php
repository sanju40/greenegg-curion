<?php

namespace App\Database\Repository;

use App\Database\Database;
use PDO;

/**
 * Affiliate Referral Repository
 * Manages the affiliate_referrals table
 */
class AffiliateReferralRepository
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Insert a new referral record
     * Uses INSERT IGNORE on the unique uppromote_referral_id so duplicate webhook
     * deliveries are silently ignored.
     *
     * @param array $data
     * @return int|null  Inserted row ID, or null if duplicate / error
     */
    public function save(array $data): ?int
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO affiliate_referrals
                (uppromote_referral_id, shopify_order_id, shopify_order_number,
                 affiliate_id, affiliate_email, affiliate_first_name, affiliate_last_name,
                 affiliate_company, program_id, program_name,
                 commission, commission_type, coupon_applied, customer_email,
                 status, raw_payload)
            VALUES
                (:uppromote_referral_id, :shopify_order_id, :shopify_order_number,
                 :affiliate_id, :affiliate_email, :affiliate_first_name, :affiliate_last_name,
                 :affiliate_company, :program_id, :program_name,
                 :commission, :commission_type, :coupon_applied, :customer_email,
                 :status, :raw_payload)
        ");

        $stmt->execute([
            ':uppromote_referral_id' => $data['uppromote_referral_id'],
            ':shopify_order_id'      => $data['shopify_order_id'] ?? null,
            ':shopify_order_number'  => $data['shopify_order_number'] ?? null,
            ':affiliate_id'          => $data['affiliate_id'] ?? null,
            ':affiliate_email'       => $data['affiliate_email'] ?? null,
            ':affiliate_first_name'  => $data['affiliate_first_name'] ?? null,
            ':affiliate_last_name'   => $data['affiliate_last_name'] ?? null,
            ':affiliate_company'     => $data['affiliate_company'] ?? null,
            ':program_id'            => $data['program_id'] ?? null,
            ':program_name'          => $data['program_name'] ?? null,
            ':commission'            => $data['commission'] ?? null,
            ':commission_type'       => $data['commission_type'] ?? null,
            ':coupon_applied'        => $data['coupon_applied'] ?? null,
            ':customer_email'        => $data['customer_email'] ?? null,
            ':status'                => $data['status'] ?? 'pending',
            ':raw_payload'           => $data['raw_payload'] ?? null,
        ]);

        $insertedId = (int)$this->db->lastInsertId();
        return $insertedId > 0 ? $insertedId : null;
    }

    /**
     * Update status (and optionally wws_transaction_id / affiliate enrichment) by DB row ID
     */
    public function updateById(int $id, array $fields): bool
    {
        $allowed = [
            'status', 'wws_transaction_id', 'error_message',
            'affiliate_company', 'program_id', 'program_name',
            'retry_count', 'next_retry_at',
        ];

        $setClauses = [];
        $params     = [':id' => $id];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $setClauses[]    = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $setClauses[] = 'updated_at = NOW()';
        $sql = 'UPDATE affiliate_referrals SET ' . implode(', ', $setClauses) . ' WHERE id = :id';

        return $this->db->prepare($sql)->execute($params);
    }

    /**
     * Update status by UpPromote referral ID
     */
    public function updateByReferralId(int $referralId, array $fields): bool
    {
        $allowed = [
            'status', 'wws_transaction_id', 'error_message',
            'affiliate_company', 'program_id', 'program_name',
            'retry_count', 'next_retry_at',
        ];

        $setClauses = [];
        $params     = [':referral_id' => $referralId];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $setClauses[]      = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $setClauses[] = 'updated_at = NOW()';
        $sql = 'UPDATE affiliate_referrals SET ' . implode(', ', $setClauses)
             . ' WHERE uppromote_referral_id = :referral_id';

        return $this->db->prepare($sql)->execute($params);
    }

    /**
     * Find record by UpPromote referral ID
     */
    public function findByReferralId(int $referralId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM affiliate_referrals WHERE uppromote_referral_id = ? LIMIT 1'
        );
        $stmt->execute([$referralId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Find record by Shopify order ID
     */
    public function findByShopifyOrderId(string $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM affiliate_referrals WHERE shopify_order_id = ? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Delete a record by UpPromote referral ID (called on successful link)
     */
    public function deleteByReferralId(int $referralId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM affiliate_referrals WHERE uppromote_referral_id = ?'
        );
        return $stmt->execute([$referralId]);
    }

    /**
     * Get referrals eligible for the cron retry job.
     *
     * Picks up both 'pending' (race condition — transaction not yet created)
     * and 'failed' (WWS update failed) rows that:
     *   - have a shopify_order_id to look up
     *   - are not yet ignored (max retries not exceeded)
     *   - are due for their next retry (next_retry_at is in the past or null)
     */
    public function getPendingWithOrderId(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM affiliate_referrals
            WHERE status IN ('pending', 'failed')
              AND shopify_order_id IS NOT NULL
              AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
