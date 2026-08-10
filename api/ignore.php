<?php
/**
 * API: Manage ignored URLs
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';

$auth = blc_require_auth_api();
$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $pattern = trim($input['pattern'] ?? '');
    $patternType = $input['pattern_type'] ?? 'exact';
    $reason = $input['reason'] ?? null;
    $siteId = !empty($input['site_id']) ? (int) $input['site_id'] : null;

    if (empty($pattern)) {
        blc_json_response(['error' => 'Pattern is required'], 400);
    }

    $allowedTypes = ['exact', 'prefix', 'domain', 'regex'];
    if (!in_array($patternType, $allowedTypes)) {
        blc_json_response(['error' => 'Invalid pattern type'], 400);
    }

    $user = $auth['user']['login'] ?? 'unknown';

    try {
        $stmt = $db->prepare('INSERT INTO ignored_urls (pattern, pattern_type, reason, site_id, created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$pattern, $patternType, $reason, $siteId, $user]);
        blc_json_response(['success' => true, 'id' => $db->lastInsertId()]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE constraint')) {
            blc_json_response(['error' => 'This pattern already exists'], 409);
        }
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        blc_json_response(['error' => 'Invalid id'], 400);
    }

    $stmt = $db->prepare('DELETE FROM ignored_urls WHERE id = ?');
    $stmt->execute([$id]);

    blc_json_response(['success' => true]);
}

blc_json_response(['error' => 'Method not allowed'], 405);
