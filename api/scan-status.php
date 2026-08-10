<?php
/**
 * API: Get scan status / progress
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';

blc_require_auth_api();

$db = Database::getInstance();

// Check for running scans (dashboard polling)
if (isset($_GET['check_running'])) {
    $running = $db->query("SELECT id, site_id, pages_crawled, links_checked, links_broken, links_warning, started_at FROM scans WHERE status = 'running'")->fetchAll();
    blc_json_response(['running_scans' => $running]);
}

$scanId = (int) ($_GET['scan_id'] ?? 0);
if ($scanId <= 0) {
    blc_json_response(['error' => 'Invalid scan_id'], 400);
}

$stmt = $db->prepare('SELECT * FROM scans WHERE id = ?');
$stmt->execute([$scanId]);
$scan = $stmt->fetch();

if (!$scan) {
    blc_json_response(['error' => 'Scan not found'], 404);
}

blc_json_response([
    'scan_id' => $scan['id'],
    'site_id' => $scan['site_id'],
    'status' => $scan['status'],
    'pages_crawled' => $scan['pages_crawled'],
    'pages_total' => $scan['pages_total'],
    'links_checked' => $scan['links_checked'],
    'links_broken' => $scan['links_broken'],
    'links_warning' => $scan['links_warning'],
    'links_ok' => $scan['links_ok'],
    'health_score' => $scan['health_score'],
    'duration_secs' => $scan['duration_secs'],
    'error_message' => $scan['error_message'],
    'started_at' => $scan['started_at'],
    'completed_at' => $scan['completed_at'],
]);
