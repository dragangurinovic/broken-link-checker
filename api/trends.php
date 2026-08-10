<?php
/**
 * API: Get trend data
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';

blc_require_auth_api();

$db = Database::getInstance();
$siteId = (int) ($_GET['site_id'] ?? 0);
$limit = min(90, max(7, (int) ($_GET['limit'] ?? 30)));

if ($siteId > 0) {
    $stmt = $db->prepare('SELECT * FROM trend_snapshots WHERE site_id = ? ORDER BY snapshot_date DESC LIMIT ?');
    $stmt->execute([$siteId, $limit]);
} else {
    $stmt = $db->prepare('SELECT t.*, s.name as site_name FROM trend_snapshots t JOIN sites s ON t.site_id = s.id ORDER BY t.snapshot_date DESC LIMIT ?');
    $stmt->execute([$limit * 7]);
}

$data = $stmt->fetchAll();

blc_json_response(['trends' => array_reverse($data)]);
