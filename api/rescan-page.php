<?php
/**
 * API: Re-scan a source page — re-fetch it, re-extract links, and
 * update/remove link records that came from this page in the latest scan.
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';
require_once BLC_ROOT . '/classes/LinkExtractor.php';
require_once BLC_ROOT . '/classes/LinkChecker.php';
require_once BLC_ROOT . '/classes/UserAgentRotator.php';
require_once BLC_ROOT . '/classes/YouTubeChecker.php';
require_once BLC_ROOT . '/classes/BlockDetector.php';

blc_require_auth_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    blc_json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$sourceUrl = $input['source_url'] ?? '';
$siteId = (int) ($input['site_id'] ?? 0);

if (empty($sourceUrl) || $siteId <= 0) {
    blc_json_response(['error' => 'source_url and site_id are required'], 400);
}

$db = Database::getInstance();

$stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND enabled = 1');
$stmt->execute([$siteId]);
$site = $stmt->fetch();
if (!$site) {
    blc_json_response(['error' => 'Site not found'], 404);
}

$stmt = $db->prepare("SELECT id FROM scans WHERE site_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1");
$stmt->execute([$siteId]);
$latestScan = $stmt->fetch();
if (!$latestScan) {
    blc_json_response(['error' => 'No completed scan found for this site'], 404);
}
$scanId = (int) $latestScan['id'];

$timeout = (int) Database::getSetting('request_timeout_secs', '30');
$connectTimeout = (int) Database::getSetting('connect_timeout_secs', '10');

$uaRotator = new UserAgentRotator($db);
$headers = $uaRotator->getHeaders(true);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $sourceUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_HTTPHEADER => $headers,
]);

$html = curl_exec($ch);
$httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
$curlError = curl_error($ch);
curl_close($ch);

if ($httpStatus === 0 || !empty($curlError)) {
    blc_json_response(['error' => 'Failed to fetch page: ' . ($curlError ?: 'Unknown error')], 502);
}

if ($httpStatus >= 400) {
    blc_json_response(['error' => "Page returned HTTP {$httpStatus}"], 502);
}

if (!str_contains($contentType, 'text/html') || empty($html)) {
    blc_json_response(['error' => 'Page did not return HTML content'], 400);
}

$extractedLinks = LinkExtractor::extract($html, $sourceUrl, [
    'check_images' => (bool) $site['check_images'],
    'check_youtube' => (bool) $site['check_youtube'],
]);

$currentTargets = array_map(fn($l) => $l['url'], $extractedLinks);

$stmt = $db->prepare('SELECT id, target_url, link_type FROM links WHERE scan_id = ? AND source_url = ?');
$stmt->execute([$scanId, $sourceUrl]);
$existingLinks = $stmt->fetchAll();

$removed = 0;
$rechecked = 0;
$added = 0;

$db->beginTransaction();
try {
    $existingByUrl = [];
    foreach ($existingLinks as $el) {
        $existingByUrl[$el['target_url']] = $el;
    }

    // Remove links no longer on the page
    foreach ($existingLinks as $el) {
        if (!in_array($el['target_url'], $currentTargets)) {
            $stmt = $db->prepare('DELETE FROM links WHERE id = ?');
            $stmt->execute([$el['id']]);
            $removed++;
        }
    }

    // Load ignore patterns
    $ignStmt = $db->prepare('SELECT pattern, pattern_type FROM ignored_urls WHERE site_id IS NULL OR site_id = ?');
    $ignStmt->execute([$siteId]);
    $ignoredPatterns = $ignStmt->fetchAll();

    $checker = new LinkChecker($timeout, $connectTimeout);

    foreach ($extractedLinks as $link) {
        $targetUrl = $link['url'];

        if (isset($existingByUrl[$targetUrl])) {
            // Link still exists — recheck it
            $existing = $existingByUrl[$targetUrl];
            $result = checkSingleLink($targetUrl, $link['type'], $checker, $uaRotator, $db, $ignoredPatterns);
            $stmt = $db->prepare("UPDATE links SET http_status = ?, status_category = ?, block_type = ?, error_message = ?, response_time_ms = ?, final_url = ?, redirect_count = ?, checked_at = datetime('now'), retry_count = retry_count + 1 WHERE id = ?");
            $stmt->execute([
                $result['http_status'],
                $result['status_category'],
                $result['block_type'] ?? null,
                $result['error_message'] ?? null,
                $result['response_time_ms'] ?? null,
                $result['final_url'] ?? null,
                $result['redirect_count'] ?? 0,
                $existing['id'],
            ]);
            $rechecked++;
        } else {
            // New link found on page — check and add
            $result = checkSingleLink($targetUrl, $link['type'], $checker, $uaRotator, $db, $ignoredPatterns);
            $isInternal = blc_get_domain($targetUrl) === blc_get_domain($site['url']);
            $stmt = $db->prepare("INSERT INTO links (scan_id, site_id, source_url, target_url, link_text, link_type, is_internal, http_status, final_url, redirect_count, response_time_ms, status_category, block_type, error_message, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))");
            $stmt->execute([
                $scanId, $siteId, $sourceUrl, $targetUrl,
                $link['text'] ?? null, $link['type'], $isInternal ? 1 : 0,
                $result['http_status'], $result['final_url'] ?? null,
                $result['redirect_count'] ?? 0, $result['response_time_ms'] ?? null,
                $result['status_category'], $result['block_type'] ?? null,
                $result['error_message'] ?? null,
            ]);
            $added++;
        }
        usleep(300000);
    }

    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    blc_json_response(['error' => 'Re-scan failed: ' . $e->getMessage()], 500);
}

blc_json_response([
    'success' => true,
    'source_url' => $sourceUrl,
    'links_on_page' => count($currentTargets),
    'removed' => $removed,
    'rechecked' => $rechecked,
    'added' => $added,
]);

function checkSingleLink(string $url, string $type, LinkChecker $checker, UserAgentRotator $uaRotator, PDO $db, array $ignoredPatterns): array {
    foreach ($ignoredPatterns as $rule) {
        $match = match ($rule['pattern_type']) {
            'exact' => $url === $rule['pattern'],
            'prefix' => str_starts_with($url, $rule['pattern']),
            'domain' => blc_get_domain($url) === $rule['pattern'],
            'regex' => (bool) @preg_match($rule['pattern'], $url),
            default => false,
        };
        if ($match) {
            return ['http_status' => null, 'status_category' => 'ignored', 'response_time_ms' => null, 'final_url' => null, 'redirect_count' => 0, 'block_type' => null, 'error_message' => 'Ignored by rule'];
        }
    }

    if ($type === 'youtube' && blc_is_youtube_url($url)) {
        $ytResult = YouTubeChecker::check($url);
        if ($ytResult['exists'] === true) {
            return ['http_status' => 200, 'status_category' => 'ok', 'response_time_ms' => null, 'final_url' => $url, 'redirect_count' => 0, 'block_type' => null, 'error_message' => null];
        } elseif ($ytResult['exists'] === false) {
            return ['http_status' => 404, 'status_category' => 'broken', 'response_time_ms' => null, 'final_url' => $url, 'redirect_count' => 0, 'block_type' => null, 'error_message' => $ytResult['error'] ?? 'Video not available'];
        }
    }

    $headers = $uaRotator->getHeaders(true);
    return $checker->check($url, $headers, true);
}
