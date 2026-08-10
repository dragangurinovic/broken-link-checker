<?php
/**
 * API: Recheck specific link(s)
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';
require_once BLC_ROOT . '/classes/LinkChecker.php';
require_once BLC_ROOT . '/classes/UserAgentRotator.php';
require_once BLC_ROOT . '/classes/YouTubeChecker.php';

blc_require_auth_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    blc_json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$linkId = (int) ($input['link_id'] ?? 0);

if ($linkId <= 0) {
    blc_json_response(['error' => 'Invalid link_id'], 400);
}

$db = Database::getInstance();
$stmt = $db->prepare('SELECT * FROM links WHERE id = ?');
$stmt->execute([$linkId]);
$link = $stmt->fetch();

if (!$link) {
    blc_json_response(['error' => 'Link not found'], 404);
}

$timeout = (int) Database::getSetting('request_timeout_secs', '30');
$connectTimeout = (int) Database::getSetting('connect_timeout_secs', '10');

// YouTube special handling
if ($link['link_type'] === 'youtube' && blc_is_youtube_url($link['target_url'])) {
    $ytResult = YouTubeChecker::check($link['target_url']);
    if ($ytResult['exists'] === true) {
        $result = ['http_status' => 200, 'status_category' => 'ok', 'error_message' => null, 'block_type' => null, 'response_time_ms' => null, 'final_url' => $link['target_url'], 'redirect_count' => 0];
    } elseif ($ytResult['exists'] === false) {
        $result = ['http_status' => 404, 'status_category' => 'broken', 'error_message' => $ytResult['error'], 'block_type' => null, 'response_time_ms' => null, 'final_url' => $link['target_url'], 'redirect_count' => 0];
    } else {
        $checker = new LinkChecker($timeout, $connectTimeout);
        $uaRotator = new UserAgentRotator($db);
        $result = $checker->check($link['target_url'], $uaRotator->getHeaders(true), false);
    }
} else {
    $checker = new LinkChecker($timeout, $connectTimeout);
    $uaRotator = new UserAgentRotator($db);
    $result = $checker->check($link['target_url'], $uaRotator->getHeaders(true), true);
}

$stmt = $db->prepare("UPDATE links SET http_status = ?, status_category = ?, block_type = ?, error_message = ?, response_time_ms = ?, final_url = ?, redirect_count = ?, checked_at = datetime('now'), retry_count = retry_count + 1 WHERE id = ?");
$stmt->execute([
    $result['http_status'],
    $result['status_category'],
    $result['block_type'] ?? null,
    $result['error_message'] ?? null,
    $result['response_time_ms'] ?? null,
    $result['final_url'] ?? null,
    $result['redirect_count'] ?? 0,
    $linkId,
]);

blc_json_response([
    'success' => true,
    'link_id' => $linkId,
    'status_category' => $result['status_category'],
    'http_status' => $result['http_status'],
]);
