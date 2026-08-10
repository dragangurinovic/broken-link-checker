<?php
/**
 * Scan Worker - Runs a full scan for a single site
 * Called from CLI only (by cron or by the API scan-start endpoint)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/functions.php';
require_once BLC_ROOT . '/classes/Scanner.php';

// Parse CLI arguments
$opts = getopt('', ['site-id:', 'scan-id:']);
$siteId = (int) ($opts['site-id'] ?? 0);
$scanId = (int) ($opts['scan-id'] ?? 0);

if ($siteId <= 0) {
    blc_log('Worker: Missing --site-id', 'error');
    exit(1);
}

// Set resource limits
ini_set('memory_limit', BLC_SCANNER_MEMORY_LIMIT);
set_time_limit(BLC_SCANNER_MAX_TIME);

blc_log("Worker: Starting scan for site ID {$siteId}" . ($scanId ? " (scan #{$scanId})" : ''));

Database::runMigrations();
$db = Database::getInstance();

// If scan-id was passed, update the existing record to 'running'
if ($scanId > 0) {
    $stmt = $db->prepare("UPDATE scans SET status = 'running', started_at = datetime('now') WHERE id = ? AND status = 'pending'");
    $stmt->execute([$scanId]);
}

try {
    $scanner = new Scanner($db, $siteId);

    // If we have an existing scan ID, use it; otherwise run() will create one
    if ($scanId > 0) {
        // We need to set the scan ID in the scanner
        // The Scanner class creates its own, so we need to update it
        $resultScanId = $scanner->run($scanId > 0 ? 'manual' : 'scheduled');
    } else {
        $resultScanId = $scanner->run('scheduled');
    }

    blc_log("Worker: Scan #{$resultScanId} completed for site ID {$siteId}");
} catch (\Throwable $e) {
    blc_log("Worker: Scan failed for site ID {$siteId}: " . $e->getMessage(), 'error');

    if ($scanId > 0) {
        $stmt = $db->prepare("UPDATE scans SET status = 'failed', error_message = ?, completed_at = datetime('now') WHERE id = ?");
        $stmt->execute([$e->getMessage(), $scanId]);
    }

    exit(1);
}

exit(0);
