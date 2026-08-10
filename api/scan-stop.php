<?php
/**
 * API: Cancel a running scan
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
$scanId = (int) ($input['scan_id'] ?? 0);

if ($scanId <= 0) {
    blc_json_response(['error' => 'Invalid scan_id'], 400);
}

$db = Database::getInstance();
$stmt = $db->prepare("UPDATE scans SET status = 'cancelled', completed_at = datetime('now') WHERE id = ? AND status IN ('running', 'pending')");
$stmt->execute([$scanId]);

if ($stmt->rowCount() === 0) {
    blc_json_response(['error' => 'Scan not found or not running'], 404);
}

blc_json_response(['success' => true, 'message' => 'Scan cancelled']);
