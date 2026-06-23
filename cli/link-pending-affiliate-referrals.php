<?php

/**
 * CLI: Link pending / failed affiliate referrals to WWS transactions
 *
 * Picks up rows in 'pending' or 'failed' status where next_retry_at is due.
 * Rows that reach MAX_RETRIES (3) are marked 'ignored' and stop being retried.
 *
 * Retry schedule (exponential backoff):
 *   attempt 1 → +5 min
 *   attempt 2 → +10 min
 *   attempt 3 → +20 min
 *   attempt 4 → ignored (no further automatic retries)
 *
 * Recommended cron — every 5 minutes:
 *   *\/5 * * * * php /path/to/cli/link-pending-affiliate-referrals.php >> /path/to/logs/affiliate-link.log 2>&1
 *
 * Usage:
 *   php cli/link-pending-affiliate-referrals.php [--limit=50]
 */

require __DIR__ . '/../src/bootstrap.php';

$config = \App\Core\Config::get();

if (empty($config['uppromote']['enabled'])) {
    echo "UpPromote integration is disabled.\n";
    exit(0);
}

$options = getopt('', ['limit:']);
$limit   = isset($options['limit']) ? (int)$options['limit'] : 50;

if ($limit < 1) {
    echo "Invalid --limit value.\n";
    exit(1);
}

echo "Linking pending affiliate referrals (limit: {$limit})...\n";

try {
    $service = new \App\Core\Services\AffiliateReferralService();
    $stats   = $service->linkPendingReferrals($limit);

    echo "\nDone.\n";
    echo "Processed:     {$stats['processed']}\n";
    echo "Linked:        {$stats['linked']}\n";
    echo "Still pending: {$stats['still_pending']}\n";
    echo "Failed:        {$stats['failed']}\n";
    echo "Ignored:       {$stats['ignored']}\n";

    exit(($stats['failed'] > 0 || $stats['ignored'] > 0) ? 1 : 0);
} catch (\Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    \App\Utils\LogHelper::error('CLI link-pending-affiliate-referrals fatal error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    exit(1);
}
