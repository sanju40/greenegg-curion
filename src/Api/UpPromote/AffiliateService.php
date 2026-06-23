<?php

namespace App\Api\UpPromote;

use App\Utils\LogHelper;

/**
 * UpPromote Affiliate Service
 * Retrieves affiliate details from the UpPromote API v2
 */
class AffiliateService
{
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Get full affiliate details by UpPromote affiliate ID
     * Endpoint: GET /api/v2/affiliates/{id}
     *
     * @param int $affiliateId
     * @return array|null Full affiliate data or null on failure
     */
    public function getAffiliateById(int $affiliateId): ?array
    {
        try {
            $response = $this->client->get("affiliates/{$affiliateId}");

            // Response wraps data in 'data' key — may be object or single-item array
            $data = $response['data'] ?? null;

            if (is_array($data)) {
                // When returned as an array, take first item
                return isset($data[0]) ? $data[0] : $data;
            }

            return null;
        } catch (\Exception $e) {
            LogHelper::warning('UpPromote: failed to fetch affiliate by ID', [
                'affiliate_id' => $affiliateId,
                'error'        => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Find a referral record for a Shopify order ID
     * Endpoint: GET /api/v2/referrals?order_id={id}
     *
     * UpPromote stores the Shopify order ID (numeric, without gid prefix) in referrals.
     * Returns the first matching referral or null if none found.
     *
     * @param string $shopifyOrderId  Numeric Shopify order ID (e.g. "6123456789")
     * @return array|null  Referral data or null
     */
    public function getReferralByOrderId(string $shopifyOrderId): ?array
    {
        try {
            $response = $this->client->get('referrals', ['order_id' => $shopifyOrderId]);
            $items    = $response['data'] ?? [];

            if (!empty($items) && isset($items[0])) {
                return $items[0];
            }

            return null;
        } catch (\Exception $e) {
            LogHelper::warning('UpPromote: failed to fetch referral by order ID', [
                'shopify_order_id' => $shopifyOrderId,
                'error'            => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Resolve the display name for use in WWS shopId
     * Priority: company name → first + last name
     *
     * @param array|null $affiliateFull  Full affiliate data from API (may be null on API failure)
     * @param array      $affiliateBasic Basic affiliate data from the webhook payload (always available)
     * @return string
     */
    public function resolveDisplayName(?array $affiliateFull, array $affiliateBasic): string
    {
        if ($affiliateFull && !empty($affiliateFull['company'])) {
            return trim($affiliateFull['company']);
        }

        $first = $affiliateFull['first_name'] ?? $affiliateBasic['first_name'] ?? '';
        $last  = $affiliateFull['last_name']  ?? $affiliateBasic['last_name']  ?? '';

        $fullName = trim($first . ' ' . $last);

        return $fullName !== '' ? $fullName : ('Affiliate #' . ($affiliateBasic['id'] ?? 'unknown'));
    }
}
