<?php
/**
 * Web-callable cron endpoint for scheduled scans.
 *
 * cPanel cron jobs often use wget/curl, which can't run PHP CLI.
 * This endpoint does the same thing as cron/run-scheduled.php but
 * works when called via HTTP, protected by a secret token.
 *
 * cPanel cron setup:
 * /usr/bin/php /path/to/broken-links/cron/run-scheduled.php >> /path/to/broken-links/data/logs/scanner.log 2>&1
 * OR (if PHP CLI doesn't work):
 * wget -q -O /dev/null "https://investor.fm/broken-links/api/cron.php?token=YOUR_TOKEN"
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/functions.php';
require_once BLC_ROOT . '/classes/ScanScheduler.php';

$token = $_GET['token'] ?? '';
$storedToken = Database::getSetting('cron_token', '');

if (empty($storedToken) || $token !== $storedToken) {
    http_response_code(403);
    echo 'Invalid token';
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);

header('Content-Type: text/plain');
echo "OK\n";
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    ob_end_flush();
    flush();
    litespeed_finish_request();
} else {
    ob_end_flush();
    flush();
}

$db = Database::getInstance();
$scheduler = new ScanScheduler($db);

blc_log('Cron (web): Checking for scheduled scans...');

if ($scheduler->isAnyScanRunning()) {
    blc_log('Cron (web): A scan is already running, skipping');
    exit(0);
}

$dueScans = $scheduler->getDueScans();

if (empty($dueScans)) {
    blc_log('Cron (web): No scans due');
    exit(0);
}

$schedule = $dueScans[0];
$siteId = $schedule['site_id'];

blc_log("Cron (web): Launching scheduled scan for site {$schedule['site_name']} (ID: {$siteId})");

$scheduler->updateNextRun($schedule['id'], $schedule);

$stmt = $db->prepare("INSERT INTO scans (site_id, status, trigger_type, started_at) VALUES (?, 'pending', 'scheduled', datetime('now'))");
$stmt->execute([$siteId]);
$scanId = (int) $db->lastInsertId();

$launched = false;

if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
    $phpBinary = PHP_BINARY ?: '/usr/bin/php';
    $workerScript = BLC_ROOT . '/cron/scan-worker.php';
    $logFile = BLC_ROOT . '/data/logs/scanner.log';
    $cmd = sprintf(
        '%s %s --site-id=%d --scan-id=%d >> %s 2>&1 &',
        escapeshellarg($phpBinary),
        escapeshellarg($workerScript),
        $siteId,
        $scanId,
        escapeshellarg($logFile)
    );
    @exec($cmd);
    $launched = true;
}

if (!$launched) {
    require_once BLC_ROOT . '/classes/Scanner.php';
    try {
        $scanner = new Scanner($db, $siteId);
        $scanner->run('scheduled', $scanId);
    } catch (\Throwable $e) {
        $stmt = $db->prepare("UPDATE scans SET status = 'failed', error_message = ?, completed_at = datetime('now') WHERE id = ?");
        $stmt->execute([$e->getMessage(), $scanId]);
        blc_log('Cron (web): Inline scan failed: ' . $e->getMessage(), 'error');
    }
}

blc_log("Cron (web): Worker launched for scan #{$scanId}");
