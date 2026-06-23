<?php

namespace App\Core\Services;

use App\Api\UpPromote\AffiliateService;
use App\Api\WwsRestService\Client as WwsClient;
use App\Api\WwsRestService\TransactionService as WwsTransactionService;
use App\Api\Shopify\OrderService as ShopifyOrderService;
use App\Database\Repository\AffiliateReferralRepository;
use App\Database\Repository\OrderQueueRepository;
use App\Utils\LogHelper;

/**
 * Affiliate Referral Service
 *
 * Handles the full referral.new flow:
 *  1. Save referral to DB
 *  2. Look up Shopify order → extract TRANSACTION_ID tag
 *  3. Fetch full affiliate details from UpPromote
 *  4. Update WWS transaction shopId with affiliate name
 *  5. Update Shopify order tags (AFFILIATE_SYNCED / AFFILIATE_SYNC_PENDING / AFFILIATE_SYNC_FAILED)
 *  6. Update DB status
 */
class AffiliateReferralService
{
    private $affiliateService;
    private $wwsTransactionService;
    private $shopifyOrderService;
    private $referralRepository;
    private $orderQueueRepository;

    // Tags managed by this service
    private const TAG_SYNCED  = 'AFFILIATE_SYNCED';
    private const TAG_PENDING = 'AFFILIATE_SYNC_PENDING';
    private const TAG_FAILED  = 'AFFILIATE_SYNC_FAILED';

    // Retry configuration
    // Total attempts = 1 (initial webhook) + MAX_RETRIES (cron)
    private const MAX_RETRIES        = 3;
    private const RETRY_BASE_MINUTES = 5; // 5 → 10 → 20 minutes (doubles each time)

    public function __construct(
        ?AffiliateService            $affiliateService       = null,
        ?WwsTransactionService       $wwsTransactionService  = null,
        ?ShopifyOrderService         $shopifyOrderService    = null,
        ?AffiliateReferralRepository $referralRepository     = null,
        ?OrderQueueRepository        $orderQueueRepository   = null
    ) {
        $this->affiliateService      = $affiliateService      ?? new AffiliateService();
        $this->wwsTransactionService = $wwsTransactionService ?? new WwsTransactionService(new WwsClient(true));
        $this->shopifyOrderService   = $shopifyOrderService   ?? new ShopifyOrderService();
        $this->referralRepository    = $referralRepository    ?? new AffiliateReferralRepository();
        $this->orderQueueRepository  = $orderQueueRepository  ?? new OrderQueueRepository();
    }

    /**
     * Main entry point — called from the webhook route
     *
     * @param array $payload  Decoded referral.new JSON payload
     * @return array  ['status' => 'linked|pending|failed', 'message' => '...']
     */
    public function handleReferralNew(array $payload): array
    {
        $referralId      = (int)($payload['id'] ?? 0);
        $shopifyOrderId  = isset($payload['order_id']) ? (string)$payload['order_id'] : null;
        $orderNumber     = isset($payload['order_number']) ? (string)$payload['order_number'] : null;
        $affiliateBasic  = $payload['affiliate'] ?? [];

        LogHelper::info('UpPromote referral.new received', [
            'referral_id'     => $referralId,
            'shopify_order_id' => $shopifyOrderId,
            'affiliate_id'    => $affiliateBasic['id'] ?? null,
        ]);

        // ── Step 1: Persist referral immediately ────────────────────────────
        $dbId = $this->referralRepository->save([
            'uppromote_referral_id' => $referralId,
            'shopify_order_id'      => $shopifyOrderId,
            'shopify_order_number'  => $orderNumber,
            'affiliate_id'          => $affiliateBasic['id'] ?? null,
            'affiliate_email'       => $affiliateBasic['email'] ?? null,
            'affiliate_first_name'  => $affiliateBasic['first_name'] ?? null,
            'affiliate_last_name'   => $affiliateBasic['last_name'] ?? null,
            'commission'            => isset($payload['commission']) ? (float)$payload['commission'] : null,
            'commission_type'       => $payload['commission_rule']['commission_type'] ?? null,
            'coupon_applied'        => $payload['coupon_applied'] ?? null,
            'customer_email'        => $payload['customer_email'] ?? null,
            'status'                => 'pending',
            'raw_payload'           => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        // Duplicate webhook — already processed
        if ($dbId === null) {
            $existing = $this->referralRepository->findByReferralId($referralId);
            LogHelper::info('UpPromote: duplicate referral webhook ignored', [
                'referral_id' => $referralId,
                'existing_status' => $existing['status'] ?? 'unknown',
            ]);
            return ['status' => $existing['status'] ?? 'pending', 'message' => 'Already recorded'];
        }

        // ── Step 2: No Shopify order yet ────────────────────────────────────
        if (!$shopifyOrderId) {
            LogHelper::info('UpPromote: referral has no order_id yet, stored as pending', [
                'referral_id' => $referralId,
            ]);
            return ['status' => 'pending', 'message' => 'No Shopify order ID in payload'];
        }

        return $this->linkReferral($dbId, $referralId, $shopifyOrderId, $affiliateBasic);
    }

    /**
     * Attempt to link a referral to a WWS transaction and update all statuses.
     * Called by handleReferralNew and also by the CLI pending-link job.
     *
     * Transaction ID is read directly from the order_queue table — no Shopify
     * API call needed just to look up a tag.
     *
     * On success:  Shopify order tags updated → record deleted from affiliate_referrals.
     * On pending:  record kept, cron job retries.
     * On failure:  record kept with error_message for investigation.
     *
     * @param int    $dbId           Row ID in affiliate_referrals
     * @param int    $referralId     UpPromote referral ID
     * @param string $shopifyOrderId Shopify order ID
     * @param array  $affiliateBasic Basic affiliate data (from webhook payload or DB)
     * @return array ['status' => ..., 'message' => ...]
     */
    public function linkReferral(int $dbId, int $referralId, string $shopifyOrderId, array $affiliateBasic): array
    {
        // ── Fetch current retry_count from DB ────────────────────────────────
        // Used to decide whether to schedule another retry or give up (ignored).
        // For test calls (referralId=0) no row exists; defaults to 0.
        $currentRetryCount = 0;
        if ($referralId > 0) {
            $existingRow       = $this->referralRepository->findByReferralId($referralId);
            $currentRetryCount = (int)($existingRow['retry_count'] ?? 0);
        }

        // ── Step 3: Look up wws_transaction_id directly from order_queue DB ──
        $orderQueueRow    = $this->orderQueueRepository->findByShopifyOrderId($shopifyOrderId);
        $wwsTransactionId = $orderQueueRow['wws_transaction_id'] ?? null;

        if (!$wwsTransactionId) {
            // WWS hasn't processed this order yet (race condition).
            // Schedule a retry unless we've already hit the limit.
            return $this->scheduleRetryOrIgnore(
                $referralId,
                $shopifyOrderId,
                $currentRetryCount,
                'pending',
                'WWS transaction not yet created for this order',
                ['order_queue_status' => $orderQueueRow['status'] ?? 'not found']
            );
        }

        // ── Step 4: Fetch full affiliate details from UpPromote ──────────────
        $affiliateFull = null;
        if (!empty($affiliateBasic['id'])) {
            $affiliateFull = $this->affiliateService->getAffiliateById((int)$affiliateBasic['id']);
        }

        $displayName = $this->affiliateService->resolveDisplayName($affiliateFull, $affiliateBasic);

        // ── Step 5: Update WWS transaction shopId ────────────────────────────
        $wwsUpdated = $this->updateWwsTransactionShopId($wwsTransactionId, $displayName);

        if (!$wwsUpdated) {
            // WWS update failed — schedule a retry unless we've hit the limit.
            $this->referralRepository->updateByReferralId($referralId, [
                'wws_transaction_id' => $wwsTransactionId,
                'affiliate_company'  => $affiliateFull['company'] ?? null,
                'program_id'         => $affiliateFull['program_id'] ?? null,
                'program_name'       => $affiliateFull['program_name'] ?? null,
            ]);

            return $this->scheduleRetryOrIgnore(
                $referralId,
                $shopifyOrderId,
                $currentRetryCount,
                'failed',
                'Failed to update WWS transaction shopId',
                ['wws_transaction_id' => $wwsTransactionId]
            );
        }

        // ── Step 6: Update Shopify order tags ────────────────────────────────
        $affiliateTagData = [
            'id'   => $affiliateBasic['id'] ?? null,
            'name' => $displayName,
        ];
        $this->updateShopifyTags($shopifyOrderId, self::TAG_SYNCED, $affiliateTagData);

        // ── Step 7: Success — delete the record, it is no longer needed ──────
        $this->referralRepository->deleteByReferralId($referralId);

        LogHelper::info('UpPromote: referral linked and record cleaned up', [
            'referral_id'        => $referralId,
            'shopify_order_id'   => $shopifyOrderId,
            'wws_transaction_id' => $wwsTransactionId,
            'affiliate_name'     => $displayName,
        ]);

        return [
            'status'             => 'linked',
            'message'            => 'Referral linked successfully',
            'wws_transaction_id' => $wwsTransactionId,
            'affiliate_name'     => $displayName,
        ];
    }

    /**
     * Decide whether to schedule another retry or permanently ignore this referral.
     *
     * Retry schedule (exponential backoff):
     *   attempt 1 (retry_count 0→1) : +5  min
     *   attempt 2 (retry_count 1→2) : +10 min
     *   attempt 3 (retry_count 2→3) : +20 min
     *   attempt 4 (retry_count 3)   : MAX_RETRIES reached → ignored
     *
     * @param int    $referralId         UpPromote referral ID (0 for test calls)
     * @param string $shopifyOrderId
     * @param int    $currentRetryCount  Value already stored in DB for this row
     * @param string $failureStatus      'pending' or 'failed'
     * @param string $errorMessage
     * @param array  $logContext         Extra fields for the log line
     * @return array ['status' => ..., 'message' => ...]
     */
    private function scheduleRetryOrIgnore(
        int    $referralId,
        string $shopifyOrderId,
        int    $currentRetryCount,
        string $failureStatus,
        string $errorMessage,
        array  $logContext = []
    ): array {
        $newRetryCount = $currentRetryCount + 1;

        if ($newRetryCount > self::MAX_RETRIES) {
            // Max retries exceeded — stop automatic retrying
            $this->referralRepository->updateByReferralId($referralId, [
                'status'        => 'ignored',
                'retry_count'   => $currentRetryCount,
                'next_retry_at' => null,
                'error_message' => $errorMessage . ' (max retries exceeded, ignored)',
            ]);
            $this->updateShopifyTags($shopifyOrderId, self::TAG_FAILED, []);

            LogHelper::warning('UpPromote: max retries exceeded — referral ignored', array_merge([
                'referral_id'      => $referralId,
                'shopify_order_id' => $shopifyOrderId,
                'retry_count'      => $currentRetryCount,
                'max_retries'      => self::MAX_RETRIES,
                'error'            => $errorMessage,
            ], $logContext));

            return [
                'status'  => 'ignored',
                'message' => 'Max retries (' . self::MAX_RETRIES . ') exceeded — ' . $errorMessage,
            ];
        }

        // Schedule next retry with exponential backoff: 5, 10, 20 minutes
        $delayMinutes = self::RETRY_BASE_MINUTES * (int)pow(2, $currentRetryCount);
        $nextRetryAt  = date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));

        $this->referralRepository->updateByReferralId($referralId, [
            'status'        => $failureStatus,
            'retry_count'   => $newRetryCount,
            'next_retry_at' => $nextRetryAt,
            'error_message' => $errorMessage,
        ]);

        if ($failureStatus === 'pending') {
            $this->updateShopifyTags($shopifyOrderId, self::TAG_PENDING, []);
        }

        LogHelper::info('UpPromote: referral scheduled for retry', array_merge([
            'referral_id'      => $referralId,
            'shopify_order_id' => $shopifyOrderId,
            'retry_count'      => $newRetryCount,
            'max_retries'      => self::MAX_RETRIES,
            'next_retry_at'    => $nextRetryAt,
            'delay_minutes'    => $delayMinutes,
            'error'            => $errorMessage,
        ], $logContext));

        return [
            'status'         => $failureStatus,
            'message'        => "Retry {$newRetryCount}/" . self::MAX_RETRIES
                                . " scheduled in {$delayMinutes} min — {$errorMessage}",
            'retry_count'    => $newRetryCount,
            'next_retry_at'  => $nextRetryAt,
        ];
    }

    /**
     * Process all pending referrals that have a shopify_order_id.
     * Used by the CLI cron job.
     *
     * @param int $limit
     * @return array Stats array
     */
    public function linkPendingReferrals(int $limit = 50): array
    {
        $pending = $this->referralRepository->getPendingWithOrderId($limit);

        $stats = ['processed' => 0, 'linked' => 0, 'still_pending' => 0, 'failed' => 0, 'ignored' => 0];

        foreach ($pending as $row) {
            $stats['processed']++;

            $affiliateBasic = [
                'id'         => $row['affiliate_id'],
                'email'      => $row['affiliate_email'],
                'first_name' => $row['affiliate_first_name'],
                'last_name'  => $row['affiliate_last_name'],
            ];

            $result = $this->linkReferral(
                (int)$row['id'],
                (int)$row['uppromote_referral_id'],
                (string)$row['shopify_order_id'],
                $affiliateBasic
            );

            switch ($result['status']) {
                case 'linked':  $stats['linked']++;        break;
                case 'pending': $stats['still_pending']++; break;
                case 'ignored': $stats['ignored']++;       break;
                default:        $stats['failed']++;        break;
            }
        }

        return $stats;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Update the shopId on a WWS transaction to include the affiliate display name
     * Fetches the current transaction first so all other fields are preserved,
     * then appends "|AFF:{name}" to the existing shopId.
     */
    private function updateWwsTransactionShopId(string $transactionId, string $affiliateName): bool
    {
        try {
            $transaction = $this->wwsTransactionService->getTransaction($transactionId);

            if (!$transaction) {
                LogHelper::error('UpPromote: WWS transaction not found', [
                    'transaction_id' => $transactionId,
                ]);
                return false;
            }

            $existingShopId = $transaction['shopId'] ?? '';

            // Idempotency — if shopId is already exactly the affiliate name, nothing to do.
            if ($existingShopId === $affiliateName) {
                LogHelper::info('UpPromote: shopId already set to affiliate name — skipping duplicate update', [
                    'transaction_id' => $transactionId,
                    'shop_id'        => $existingShopId,
                ]);
                return true;
            }

            // Replace whatever was there (e.g. "BGE") with just the partner name.
            $newShopId = $affiliateName;

            // Build a clean minimal payload — avoids sending back read-only or
            // complex nested fields from the GET response that WWS rejects silently.
            $payload = $this->buildTransactionUpdatePayload($transaction, $newShopId);

            LogHelper::info('UpPromote: sending transaction update to WWS', [
                'transaction_id'  => $transactionId,
                'old_shop_id'     => $existingShopId,
                'new_shop_id'     => $newShopId,
                'payload_keys'    => array_keys($payload),
                'items_count'     => count($payload['items'] ?? []),
            ]);

            $updated = $this->wwsTransactionService->updateTransaction($transactionId, $payload);

            if (!$updated) {
                LogHelper::error('UpPromote: WWS updateTransaction returned null', [
                    'transaction_id' => $transactionId,
                ]);
                return false;
            }

            // ── Verify the change actually persisted ──────────────────────────
            // WWS sometimes returns 200 OK but silently ignores the payload.
            // Re-fetching is the only reliable way to confirm.
            $verify = $this->wwsTransactionService->getTransaction($transactionId);
            $savedShopId = $verify['shopId'] ?? '';

            if ($savedShopId !== $newShopId) {
                LogHelper::error('UpPromote: WWS update appeared to succeed but shopId did not change', [
                    'transaction_id'  => $transactionId,
                    'expected_shop_id' => $newShopId,
                    'actual_shop_id'  => $savedShopId,
                    'payload_sent'    => $payload,
                    'response_keys'   => array_keys($updated ?? []),
                ]);
                return false;
            }

            LogHelper::info('UpPromote: WWS transaction shopId updated and verified', [
                'transaction_id' => $transactionId,
                'old_shop_id'    => $existingShopId,
                'new_shop_id'    => $newShopId,
            ]);

            return true;
        } catch (\Exception $e) {
            LogHelper::error('UpPromote: exception updating WWS transaction', [
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build a minimal but complete transaction payload for the WWS update endpoint.
     *
     * The WWS API requires the full transaction object on POST, but sending back
     * the raw GET response as-is causes silent failures — the response contains
     * expanded nested objects (full product details in items, lookup objects, etc.)
     * that the write endpoint does not accept.
     *
     * We keep only the writable core fields and re-wrap lookup objects to the
     * simple {id} format the API expects.
     */
    private function buildTransactionUpdatePayload(array $t, string $newShopId): array
    {
        // Normalise a lookup object: keep only {id}, discard description/_lookupType
        $ref = static function($val) {
            if (is_array($val) && isset($val['id'])) {
                return ['id' => $val['id']];
            }
            return $val;
        };

        // Rebuild items in the same minimal format used when creating the transaction
        $items = [];
        foreach ($t['items'] ?? [] as $item) {
            $product = $item['product'] ?? $item['article'] ?? null;
            $rebuilt = [
                'quantity' => $item['quantity'] ?? 1,
                'price'    => $item['price'] ?? $item['basePrice'] ?? 0,
                'product'  => $product ? $ref($product) : null,
            ];
            if ($rebuilt['product']) {
                $items[] = $rebuilt;
            }
        }

        $payload = [
            'transactionType' => $ref($t['transactionType'] ?? null),
            'orderNumber'     => $t['orderNumber'] ?? null,
            'shopId'          => $newShopId,
            'address'         => $ref($t['address'] ?? null),
            'shippingMethod'  => $ref($t['shippingMethod'] ?? null),
            'shippingCosts'   => $t['shippingCosts'] ?? 0,
            'packagingCosts'  => $t['packagingCosts'] ?? 0,
            'items'           => $items,
        ];

        // Strip any null top-level values to keep the payload clean
        return array_filter($payload, fn($v) => $v !== null);
    }

    /**
     * Update Shopify order tags and note_attributes based on sync outcome.
     * Fetches current order internally so callers don't need to.
     *
     * Tags updated:   AFFILIATE_SYNCED / AFFILIATE_SYNC_PENDING / AFFILIATE_SYNC_FAILED
     *                 + AFFILIATE_ID:{id}  + AFFILIATE:{name}  (on success)
     *
     * Note attributes written (visible as "Additional details" in the order):
     *   Affiliate Name   → company or first+last name
     *   Affiliate ID     → UpPromote affiliate numeric ID
     *   Affiliate Status → Synced / Pending / Failed
     *
     * @param string $shopifyOrderId
     * @param string $outcomeTag     One of TAG_SYNCED, TAG_PENDING, TAG_FAILED
     * @param array  $affiliateData  Optional: ['id' => int, 'name' => string]
     */
    private function updateShopifyTags(
        string $shopifyOrderId,
        string $outcomeTag,
        array  $affiliateData
    ): void {
        try {
            // Fetch current order to preserve existing tags and note_attributes
            $shopifyOrder = $this->shopifyOrderService->getOrder($shopifyOrderId);

            // ── Tags ──────────────────────────────────────────────────────────
            $currentTags = [];
            if ($shopifyOrder && !empty($shopifyOrder['tags'])) {
                $currentTags = array_map('trim', explode(',', $shopifyOrder['tags']));
            }

            // Strip any previous affiliate status / identity tags
            $tags = array_values(array_filter($currentTags, function ($tag) {
                return $tag !== self::TAG_SYNCED
                    && $tag !== self::TAG_PENDING
                    && $tag !== self::TAG_FAILED
                    && strpos($tag, 'AFFILIATE_ID:') !== 0
                    && strpos($tag, 'AFFILIATE:') !== 0;
            }));

            $tags[] = $outcomeTag;

            if ($outcomeTag === self::TAG_SYNCED && !empty($affiliateData)) {
                if (!empty($affiliateData['id'])) {
                    $tags[] = 'AFFILIATE_ID:' . $affiliateData['id'];
                }
                if (!empty($affiliateData['name'])) {
                    $tags[] = 'AFFILIATE:' . $affiliateData['name'];
                }
            }

            // ── Note attributes ───────────────────────────────────────────────
            // Shopify stores these as [{name, value}, …]. We preserve unrelated
            // attributes and replace only the Affiliate-prefixed ones.
            $existingAttrs = $shopifyOrder['note_attributes'] ?? [];

            // Keys we own — remove stale ones before re-adding
            $affiliateAttrKeys = ['Affiliate Name', 'Affiliate ID', 'Affiliate Status'];
            $noteAttributes = array_values(array_filter(
                $existingAttrs,
                fn($attr) => !in_array($attr['name'] ?? '', $affiliateAttrKeys, true)
            ));

            // Map outcome tag → human-readable status
            $statusLabel = match ($outcomeTag) {
                self::TAG_SYNCED  => 'Synced',
                self::TAG_PENDING => 'Pending',
                self::TAG_FAILED  => 'Failed',
                default           => $outcomeTag,
            };

            $noteAttributes[] = ['name' => 'Affiliate Status', 'value' => $statusLabel];

            if ($outcomeTag === self::TAG_SYNCED && !empty($affiliateData)) {
                if (!empty($affiliateData['name'])) {
                    $noteAttributes[] = ['name' => 'Affiliate Name', 'value' => $affiliateData['name']];
                }
                if (!empty($affiliateData['id'])) {
                    $noteAttributes[] = ['name' => 'Affiliate ID', 'value' => (string)$affiliateData['id']];
                }
            }

            // ── Single API call — update both tags and note_attributes ─────────
            $this->shopifyOrderService->updateOrder($shopifyOrderId, [
                'tags'             => implode(', ', $tags),
                'note_attributes'  => $noteAttributes,
            ]);

            LogHelper::info('UpPromote: Shopify order tags and note_attributes updated', [
                'shopify_order_id' => $shopifyOrderId,
                'outcome_tag'      => $outcomeTag,
                'tags'             => $tags,
                'note_attributes'  => $noteAttributes,
            ]);
        } catch (\Exception $e) {
            LogHelper::warning('UpPromote: failed to update Shopify order tags', [
                'shopify_order_id' => $shopifyOrderId,
                'error'            => $e->getMessage(),
            ]);
        }
    }
}
