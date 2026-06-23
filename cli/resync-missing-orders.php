<?php

/**
 * CLI: Resync Missing Orders
 *
 * Fetches all paid Shopify orders from the last N days, identifies which ones
 * were never sent to Curion (not completed in order_queue), and processes them.
 * After each order is synced, checks whether it has an UpPromote referral and
 * syncs the affiliate shopId as well.
 *
 * Usage:
 *   php cli/resync-missing-orders.php [--days=7] [--dry-run]
 *
 * Options:
 *   --days=N     How many days back to look (default: 7)
 *   --dry-run    List orders that would be synced without actually syncing them
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Api\Shopify\Client as ShopifyClient;
use App\Api\UpPromote\AffiliateService;
use App\Core\Services\AffiliateReferralService;
use App\Core\Services\OrderProcessingService;
use App\Database\Repository\OrderQueueRepository;
use App\Utils\LogHelper;

$config = \App\Core\Config::get();

if (!$config['cli_enabled']) {
    echo "CLI commands are disabled.\n";
    exit(1);
}

// ─── Parse arguments ──────────────────────────────────────────────────────────

$options = getopt('', ['days:', 'dry-run']);
$days    = isset($options['days']) ? max(1, (int)$options['days']) : 7;
$dryRun  = isset($options['dry-run']);

$createdAtMin = (new \DateTime("-{$days} days"))->format(\DateTime::ATOM);

echo "=======================================================\n";
echo " Resync Missing Orders\n";
echo "=======================================================\n";
echo " Lookback  : {$days} day(s) (since {$createdAtMin})\n";
echo " Mode      : " . ($dryRun ? "DRY RUN (no writes)" : "LIVE") . "\n";
echo "=======================================================\n\n";

// ─── Services ─────────────────────────────────────────────────────────────────

$shopifyClient          = new ShopifyClient();
$orderQueue             = new OrderQueueRepository();
$orderProcessingService = new OrderProcessingService();
$affiliateService       = new AffiliateService();
$affiliateReferralSvc   = new AffiliateReferralService();

// ─── Counters ─────────────────────────────────────────────────────────────────

$stats = [
    'fetched'            => 0,
    'already_synced'     => 0,
    'synced'             => 0,
    'affiliate_synced'   => 0,
    'affiliate_skipped'  => 0,
    'failed'             => 0,
];

// ─── Fetch Shopify orders (paginated by since_id) ────────────────────────────

echo "Fetching paid orders from Shopify...\n";

$sinceId = null;
$page    = 1;
$orders  = [];

do {
    $params = [
        'status'           => 'any',
        'financial_status' => 'paid',
        'created_at_min'   => $createdAtMin,
        'limit'            => 250,
        'order'            => 'id asc',
    ];

    if ($sinceId !== null) {
        $params['since_id'] = $sinceId;
    }

    $response = $shopifyClient->get('orders.json', $params);
    $batch    = $response['orders'] ?? [];

    if (empty($batch)) {
        break;
    }

    $orders  = array_merge($orders, $batch);
    $sinceId = end($batch)['id'];
    $page++;

    // UpPromote cursor is set; loop until Shopify returns fewer than 250 orders
} while (count($batch) === 250);

$stats['fetched'] = count($orders);
echo "Found {$stats['fetched']} paid order(s) in the last {$days} day(s).\n\n";

if (empty($orders)) {
    echo "Nothing to resync.\n";
    exit(0);
}

// ─── Process each order ───────────────────────────────────────────────────────

foreach ($orders as $orderData) {
    $shopifyOrderId  = (string)$orderData['id'];
    $orderNumber     = $orderData['name'] ?? "#{$shopifyOrderId}";
    $orderTags       = array_map('trim', explode(',', $orderData['tags'] ?? ''));

    // ── Duplicate guard ───────────────────────────────────────────────────────
    $existing = $orderQueue->findByShopifyOrderId($shopifyOrderId);

    if ($existing && $existing['status'] === 'completed' && !empty($existing['wws_transaction_id'])) {
        echo "  [SKIP] {$orderNumber} — already synced (txn: {$existing['wws_transaction_id']})\n";
        $stats['already_synced']++;
        continue;
    }

    $statusNote = $existing
        ? " (queue status: {$existing['status']})"
        : " (not in queue)";
    echo "  [SYNC] {$orderNumber}{$statusNote}... ";

    if ($dryRun) {
        echo "DRY RUN — skipped\n";
        $stats['synced']++;
        continue;
    }

    // ── Add / reset in queue then process ─────────────────────────────────────
    try {
        $orderQueue->addOrder($shopifyOrderId, $orderNumber, $orderData);

        $result = $orderProcessingService->processOrder($orderData);

        $txnId = !empty($result['results']) ? reset($result['results'])['transaction_id'] : 'N/A';
        echo "OK (txn: {$txnId})\n";
        $stats['synced']++;

    } catch (\Exception $e) {
        echo "FAILED — " . $e->getMessage() . "\n";
        LogHelper::error('resync-missing-orders: order processing failed', [
            'shopify_order_id'     => $shopifyOrderId,
            'shopify_order_number' => $orderNumber,
            'error'                => $e->getMessage(),
        ]);
        $stats['failed']++;
        continue;
    }

    // ── Affiliate sync ────────────────────────────────────────────────────────
    // Only run if the order carries an UpPromote tag (added by the UpPromote app)
    $hasAffiliateTag = false;
    foreach ($orderTags as $tag) {
        if (stripos($tag, 'uppromote') !== false) {
            $hasAffiliateTag = true;
            break;
        }
    }

    if (!$hasAffiliateTag) {
        continue;
    }

    echo "         → UpPromote tag detected, looking up referral... ";

    try {
        $referral = $affiliateService->getReferralByOrderId($shopifyOrderId);

        if (!$referral) {
            echo "no referral found — skipped\n";
            $stats['affiliate_skipped']++;
            continue;
        }

        $referralId    = (int)($referral['id'] ?? 0);
        $affiliateData = $referral['affiliate'] ?? $referral;

        // Build the minimal structure linkReferral() needs
        $affiliateBasic = [
            'id'         => $affiliateData['id'] ?? null,
            'first_name' => $affiliateData['first_name'] ?? '',
            'last_name'  => $affiliateData['last_name'] ?? '',
        ];

        $linkResult = $affiliateReferralSvc->linkReferral(
            0,                // no DB row — this is a manual resync
            $referralId,
            $shopifyOrderId,
            $affiliateBasic
        );

        if (($linkResult['status'] ?? '') === 'success') {
            echo "affiliate synced\n";
            $stats['affiliate_synced']++;
        } else {
            echo "affiliate pending — " . ($linkResult['message'] ?? 'see logs') . "\n";
            $stats['affiliate_skipped']++;
        }

    } catch (\Exception $e) {
        echo "affiliate FAILED — " . $e->getMessage() . "\n";
        LogHelper::error('resync-missing-orders: affiliate sync failed', [
            'shopify_order_id' => $shopifyOrderId,
            'error'            => $e->getMessage(),
        ]);
        $stats['affiliate_skipped']++;
    }
}

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n=======================================================\n";
echo " Summary\n";
echo "=======================================================\n";
echo " Fetched from Shopify  : {$stats['fetched']}\n";
echo " Already synced        : {$stats['already_synced']}\n";
echo " Synced now            : {$stats['synced']}\n";
echo " Affiliate synced      : {$stats['affiliate_synced']}\n";
echo " Affiliate skipped     : {$stats['affiliate_skipped']}\n";
echo " Failed                : {$stats['failed']}\n";
echo "=======================================================\n";

exit($stats['failed'] > 0 ? 1 : 0);
