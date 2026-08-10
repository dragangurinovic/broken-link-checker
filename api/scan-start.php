<?php
/**
 * API: Start a scan for a site
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';

blc_require_auth_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    blc_json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$siteId = (int) ($input['site_id'] ?? 0);

if ($siteId <= 0) {
    blc_json_response(['error' => 'Invalid site_id'], 400);
}

$db = Database::getInstance();

// Check site exists
$stmt = $db->prepare('SELECT id, name FROM sites WHERE id = ? AND enabled = 1');
$stmt->execute([$siteId]);
$site = $stmt->fetch();

if (!$site) {
    blc_json_response(['error' => 'Site not found'], 404);
}

// Check for already running scan
$stmt = $db->prepare("SELECT id FROM scans WHERE site_id = ? AND status = 'running'");
$stmt->execute([$siteId]);
if ($stmt->fetch()) {
    blc_json_response(['error' => 'A scan is already running for this site'], 409);
}

// Check max concurrent scans
$maxConcurrent = (int) Database::getSetting('max_concurrent_scans', '1');
$stmt = $db->prepare("SELECT COUNT(*) FROM scans WHERE status = 'running'");
$stmt->execute();
if ($stmt->fetchColumn() >= $maxConcurrent) {
    blc_json_response(['error' => 'Maximum concurrent scans reached. Please wait for the current scan to finish.'], 429);
}

// Create pending scan
$stmt = $db->prepare("INSERT INTO scans (site_id, status, trigger_type, started_at) VALUES (?, 'pending', 'manual', datetime('now'))");
$stmt->execute([$siteId]);
$scanId = (int) $db->lastInsertId();

// Launch scanner in background
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

exec($cmd);

blc_json_response([
    'success' => true,
    'scan_id' => $scanId,
    'site_id' => $siteId,
    'message' => "Scan started for {$site['name']}",
]);
