<?php
/**
 * API: Bulk actions on links
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';

$auth = blc_require_auth_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    blc_json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$linkIds = $input['link_ids'] ?? [];

if (empty($linkIds) || !is_array($linkIds)) {
    blc_json_response(['error' => 'No links selected'], 400);
}

$linkIds = array_map('intval', $linkIds);
$placeholders = implode(',', array_fill(0, count($linkIds), '?'));

$db = Database::getInstance();

switch ($action) {
    case 'ignore':
        $stmt = $db->prepare("SELECT DISTINCT target_url, site_id FROM links WHERE id IN ({$placeholders})");
        $stmt->execute($linkIds);
        $urls = $stmt->fetchAll();

        $user = $auth['user']['login'] ?? 'unknown';
        $ignoreStmt = $db->prepare('INSERT OR IGNORE INTO ignored_urls (pattern, pattern_type, reason, site_id, created_by) VALUES (?, \'exact\', \'Bulk ignored\', ?, ?)');

        foreach ($urls as $url) {
            $ignoreStmt->execute([$url['target_url'], $url['site_id'], $user]);
        }

        $updateStmt = $db->prepare("UPDATE links SET status_category = 'ignored' WHERE id IN ({$placeholders})");
        $updateStmt->execute($linkIds);

        blc_json_response(['success' => true, 'affected' => count($urls)]);
        break;

    case 'recheck':
        require_once BLC_ROOT . '/classes/LinkChecker.php';
        require_once BLC_ROOT . '/classes/UserAgentRotator.php';

        $checker = new LinkChecker();
        $uaRotator = new UserAgentRotator($db);
        $headers = $uaRotator->getHeaders(true);

        $stmt = $db->prepare("SELECT id, target_url FROM links WHERE id IN ({$placeholders})");
        $stmt->execute($linkIds);
        $links = $stmt->fetchAll();

        $updated = 0;
        foreach ($links as $link) {
            $result = $checker->check($link['target_url'], $headers, true);
            $upd = $db->prepare("UPDATE links SET http_status = ?, status_category = ?, block_type = ?, error_message = ?, response_time_ms = ?, checked_at = datetime('now'), retry_count = retry_count + 1 WHERE id = ?");
            $upd->execute([
                $result['http_status'],
                $result['status_category'],
                $result['block_type'] ?? null,
                $result['error_message'] ?? null,
                $result['response_time_ms'] ?? null,
                $link['id'],
            ]);
            $updated++;
            usleep(500000); // 500ms between rechecks
        }

        blc_json_response(['success' => true, 'rechecked' => $updated]);
        break;

    default:
        blc_json_response(['error' => 'Unknown action: ' . $action], 400);
}
