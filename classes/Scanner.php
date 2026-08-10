<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/LinkExtractor.php';
require_once __DIR__ . '/LinkChecker.php';
require_once __DIR__ . '/YouTubeChecker.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/UserAgentRotator.php';
require_once __DIR__ . '/BlockDetector.php';
require_once __DIR__ . '/HealthScore.php';
require_once __DIR__ . '/Notifier.php';

class Scanner {
    private PDO $db;
    private int $siteId;
    private int $scanId;
    private array $site;
    private LinkChecker $checker;
    private RateLimiter $rateLimiter;
    private UserAgentRotator $uaRotator;
    private array $visitedUrls = [];
    private array $checkedTargets = [];
    private array $ignoredPatterns = [];
    private int $concurrentRequests;
    private int $maxRetries;
    private bool $cancelled = false;

    public function __construct(PDO $db, int $siteId) {
        $this->db = $db;
        $this->siteId = $siteId;

        $stmt = $db->prepare('SELECT * FROM sites WHERE id = ? AND enabled = 1');
        $stmt->execute([$siteId]);
        $this->site = $stmt->fetch();

        if (!$this->site) {
            throw new RuntimeException("Site not found or disabled: {$siteId}");
        }

        $timeout = (int) Database::getSetting('request_timeout_secs', '30');
        $connectTimeout = (int) Database::getSetting('connect_timeout_secs', '10');
        $this->concurrentRequests = (int) Database::getSetting('concurrent_requests', '5');
        $this->maxRetries = (int) Database::getSetting('max_retries', '2');

        $this->checker = new LinkChecker($timeout, $connectTimeout);
        $this->rateLimiter = new RateLimiter($this->site['rate_limit_ms']);
        $this->uaRotator = new UserAgentRotator($db);

        $this->loadIgnoredPatterns();
    }

    private function loadIgnoredPatterns(): void {
        $stmt = $this->db->prepare('SELECT pattern, pattern_type FROM ignored_urls WHERE site_id IS NULL OR site_id = ?');
        $stmt->execute([$this->siteId]);
        $this->ignoredPatterns = $stmt->fetchAll();
    }

    private function isIgnored(string $url): bool {
        foreach ($this->ignoredPatterns as $rule) {
            switch ($rule['pattern_type']) {
                case 'exact':
                    if ($url === $rule['pattern']) return true;
                    break;
                case 'prefix':
                    if (str_starts_with($url, $rule['pattern'])) return true;
                    break;
                case 'domain':
                    if (blc_get_domain($url) === $rule['pattern']) return true;
                    break;
                case 'regex':
                    if (@preg_match($rule['pattern'], $url)) return true;
                    break;
            }
        }
        return false;
    }

    public function run(string $triggerType = 'manual', ?int $existingScanId = null): int {
        if ($existingScanId) {
            $this->scanId = $existingScanId;
            $stmt = $this->db->prepare("UPDATE scans SET status = 'running', started_at = datetime('now') WHERE id = ?");
            $stmt->execute([$this->scanId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM scans WHERE site_id = ? AND status = 'running'");
            $stmt->execute([$this->siteId]);
            if ($stmt->fetchColumn() > 0) {
                throw new RuntimeException("A scan is already running for this site");
            }

            $stmt = $this->db->prepare("INSERT INTO scans (site_id, status, trigger_type, started_at) VALUES (?, 'running', ?, datetime('now'))");
            $stmt->execute([$this->siteId, $triggerType]);
            $this->scanId = (int) $this->db->lastInsertId();
        }

        $this->log('info', "Starting scan for {$this->site['name']} ({$this->site['url']})");

        try {
            $this->crawl();
            $this->finalizeScan('completed');
        } catch (\Throwable $e) {
            $this->log('error', "Scan failed: " . $e->getMessage());
            $this->finalizeScan('failed', $e->getMessage());
        }

        return $this->scanId;
    }

    private function crawl(): void {
        $startUrl = rtrim($this->site['url'], '/');
        $siteDomain = blc_get_domain($startUrl);
        $queue = [['url' => $startUrl, 'depth' => 0]];
        $maxPages = $this->site['max_pages'];
        $maxDepth = $this->site['max_depth'];
        $pagesProcessed = 0;
        $linksBuffer = [];
        $batchSize = 50;

        while (!empty($queue) && !$this->cancelled) {
            $item = array_shift($queue);
            $url = $item['url'];
            $depth = $item['depth'];

            if (isset($this->visitedUrls[$url])) continue;
            if ($depth > $maxDepth) continue;
            if ($pagesProcessed >= $maxPages) break;

            $this->visitedUrls[$url] = true;

            // Rate limit
            $domain = blc_get_domain($url);
            $this->rateLimiter->waitIfNeeded($domain);

            // Fetch page
            $headers = $this->uaRotator->getHeaders(false);
            $startTime = microtime(true);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => $headers,
            ]);

            $html = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $error = curl_error($ch);
            curl_close($ch);

            $this->rateLimiter->recordRequest($domain);

            // Save page
            $stmt = $this->db->prepare('INSERT INTO pages (scan_id, site_id, url, http_status, content_type, response_time_ms, crawl_depth) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$this->scanId, $this->siteId, $url, $httpStatus ?: null, $contentType, $responseTime, $depth]);
            $pageId = (int) $this->db->lastInsertId();

            $pagesProcessed++;
            $this->updateProgress($pagesProcessed);

            if ($httpStatus < 200 || $httpStatus >= 400 || empty($html)) {
                continue;
            }

            if (!str_contains($contentType, 'text/html')) {
                continue;
            }

            // Extract links
            $extractedLinks = LinkExtractor::extract($html, $url, [
                'check_images' => (bool) $this->site['check_images'],
                'check_youtube' => (bool) $this->site['check_youtube'],
            ]);

            foreach ($extractedLinks as $link) {
                $targetUrl = $link['url'];
                if (empty($targetUrl)) continue;

                $isInternal = $this->isInternalUrl($targetUrl, $siteDomain);

                // Queue internal pages for crawling
                if ($isInternal && $this->site['crawl_internal'] && !isset($this->visitedUrls[$targetUrl])) {
                    $normalized = rtrim($targetUrl, '/');
                    if (!isset($this->visitedUrls[$normalized])) {
                        $queue[] = ['url' => $normalized, 'depth' => $depth + 1];
                    }
                }

                // Skip external if not checking
                if (!$isInternal && !$this->site['crawl_external']) continue;

                // Check if already checked this target in this scan
                if (isset($this->checkedTargets[$targetUrl])) {
                    // Still record the link occurrence
                    $linksBuffer[] = [
                        'scan_id' => $this->scanId,
                        'site_id' => $this->siteId,
                        'source_page_id' => $pageId,
                        'source_url' => $url,
                        'target_url' => $targetUrl,
                        'link_text' => $link['text'],
                        'link_type' => $link['type'],
                        'is_internal' => $isInternal ? 1 : 0,
                        'status_category' => $this->checkedTargets[$targetUrl]['status_category'],
                        'http_status' => $this->checkedTargets[$targetUrl]['http_status'],
                        'response_time_ms' => $this->checkedTargets[$targetUrl]['response_time_ms'],
                        'final_url' => $this->checkedTargets[$targetUrl]['final_url'],
                        'redirect_count' => $this->checkedTargets[$targetUrl]['redirect_count'],
                        'block_type' => $this->checkedTargets[$targetUrl]['block_type'],
                        'error_message' => $this->checkedTargets[$targetUrl]['error_message'],
                    ];
                } else {
                    // Check the link
                    $linkResult = $this->checkLink($targetUrl, $link['type']);
                    $this->checkedTargets[$targetUrl] = $linkResult;

                    $linksBuffer[] = [
                        'scan_id' => $this->scanId,
                        'site_id' => $this->siteId,
                        'source_page_id' => $pageId,
                        'source_url' => $url,
                        'target_url' => $targetUrl,
                        'link_text' => $link['text'],
                        'link_type' => $link['type'],
                        'is_internal' => $isInternal ? 1 : 0,
                        'status_category' => $linkResult['status_category'],
                        'http_status' => $linkResult['http_status'],
                        'response_time_ms' => $linkResult['response_time_ms'],
                        'final_url' => $linkResult['final_url'],
                        'redirect_count' => $linkResult['redirect_count'],
                        'block_type' => $linkResult['block_type'],
                        'error_message' => $linkResult['error_message'],
                    ];
                }

                // Flush buffer
                if (count($linksBuffer) >= $batchSize) {
                    $this->flushLinks($linksBuffer);
                    $linksBuffer = [];
                }
            }

            // Check for cancellation
            $stmt = $this->db->prepare("SELECT status FROM scans WHERE id = ?");
            $stmt->execute([$this->scanId]);
            $status = $stmt->fetchColumn();
            if ($status === 'cancelled') {
                $this->cancelled = true;
                $this->log('info', 'Scan cancelled by user');
            }
        }

        // Flush remaining links
        if (!empty($linksBuffer)) {
            $this->flushLinks($linksBuffer);
        }
    }

    private function checkLink(string $url, string $type): array {
        // Check ignore list
        if ($this->isIgnored($url)) {
            return [
                'http_status' => null,
                'status_category' => 'ignored',
                'response_time_ms' => null,
                'final_url' => null,
                'redirect_count' => 0,
                'block_type' => null,
                'error_message' => 'Ignored by rule',
            ];
        }

        // YouTube special handling
        if ($type === 'youtube' && blc_is_youtube_url($url)) {
            $ytResult = YouTubeChecker::check($url);
            if ($ytResult['exists'] === true) {
                return [
                    'http_status' => 200,
                    'status_category' => 'ok',
                    'response_time_ms' => null,
                    'final_url' => $url,
                    'redirect_count' => 0,
                    'block_type' => null,
                    'error_message' => null,
                ];
            } elseif ($ytResult['exists'] === false) {
                return [
                    'http_status' => 404,
                    'status_category' => 'broken',
                    'response_time_ms' => null,
                    'final_url' => $url,
                    'redirect_count' => 0,
                    'block_type' => null,
                    'error_message' => $ytResult['error'] ?? 'Video not available',
                ];
            }
            // If null (inconclusive), fall through to regular check
        }

        $domain = blc_get_domain($url);
        $this->rateLimiter->waitIfNeeded($domain, 1000); // External: 1s rate limit

        // First attempt with bot UA
        $headers = $this->uaRotator->getHeaders(false);
        $result = $this->checker->check($url, $headers, true);
        $this->rateLimiter->recordRequest($domain);

        // If blocked, retry with browser UA
        if ($result['status_category'] === 'blocked' && $this->maxRetries > 0) {
            $this->log('debug', "Retrying blocked URL with browser UA: {$url}");
            usleep(Database::getSetting('retry_delay_ms', '2000') * 1000);

            $browserHeaders = $this->uaRotator->getHeaders(true);
            $retryResult = $this->checker->check($url, $browserHeaders, false);
            $this->rateLimiter->recordRequest($domain);

            if ($retryResult['status_category'] === 'ok') {
                $retryResult['block_type'] = 'waf';
                $result = $retryResult;
            } else {
                $result['retry_count'] = 1;
            }
        }

        // If timeout or server error, retry once
        if (in_array($result['status_category'], ['timeout', 'warning']) && $this->maxRetries > 0) {
            usleep(Database::getSetting('retry_delay_ms', '2000') * 1000);

            $headers = $this->uaRotator->getHeaders(true);
            $retryResult = $this->checker->check($url, $headers, false);
            $this->rateLimiter->recordRequest($domain);

            if ($retryResult['status_category'] === 'ok') {
                $result = $retryResult;
            } else {
                $result['retry_count'] = ($result['retry_count'] ?? 0) + 1;
            }
        }

        return $result;
    }

    private function isInternalUrl(string $url, string $siteDomain): bool {
        $urlDomain = blc_get_domain($url);
        return $urlDomain === $siteDomain || str_ends_with($urlDomain, '.' . $siteDomain);
    }

    private function flushLinks(array $links): void {
        $stmt = $this->db->prepare('INSERT INTO links (scan_id, site_id, source_page_id, source_url, target_url, link_text, link_type, is_internal, http_status, final_url, redirect_count, response_time_ms, status_category, block_type, error_message, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))');

        $this->db->beginTransaction();
        try {
            foreach ($links as $link) {
                $stmt->execute([
                    $link['scan_id'],
                    $link['site_id'],
                    $link['source_page_id'],
                    $link['source_url'],
                    $link['target_url'],
                    $link['link_text'] ?? null,
                    $link['link_type'],
                    $link['is_internal'],
                    $link['http_status'],
                    $link['final_url'],
                    $link['redirect_count'] ?? 0,
                    $link['response_time_ms'],
                    $link['status_category'],
                    $link['block_type'],
                    $link['error_message'],
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->log('error', 'Failed to flush links: ' . $e->getMessage());
        }
    }

    private function updateProgress(int $pagesProcessed): void {
        $linksChecked = count($this->checkedTargets);
        $stmt = $this->db->prepare("UPDATE scans SET pages_crawled = ?, links_checked = ? WHERE id = ?");
        $stmt->execute([$pagesProcessed, $linksChecked, $this->scanId]);
    }

    private function finalizeScan(string $status, ?string $errorMessage = null): void {
        // Count link categories
        $stmt = $this->db->prepare("SELECT status_category, COUNT(*) as cnt FROM links WHERE scan_id = ? GROUP BY status_category");
        $stmt->execute([$this->scanId]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $healthScore = HealthScore::calculate($this->db, $this->scanId);

        $stmt = $this->db->prepare("UPDATE scans SET
            status = ?,
            links_checked = ?,
            links_broken = ?,
            links_warning = ?,
            links_ok = ?,
            health_score = ?,
            duration_secs = CAST((julianday(datetime('now')) - julianday(started_at)) * 86400 AS INTEGER),
            error_message = ?,
            completed_at = datetime('now')
            WHERE id = ?");
        $stmt->execute([
            $status,
            array_sum($counts),
            ($counts['broken'] ?? 0),
            ($counts['warning'] ?? 0) + ($counts['blocked'] ?? 0),
            ($counts['ok'] ?? 0),
            $healthScore,
            $errorMessage,
            $this->scanId,
        ]);

        $this->log('info', "Scan {$status}. Pages: {$this->site['max_pages']}, Links: " . array_sum($counts) . ", Broken: " . ($counts['broken'] ?? 0));

        // Save trend snapshot
        HealthScore::saveTrendSnapshot($this->db, $this->scanId, $this->siteId);

        // Send notifications
        if ($status === 'completed') {
            try {
                $notifier = new Notifier($this->db);
                $notifier->notify($this->scanId);
            } catch (\Throwable $e) {
                $this->log('error', 'Notification failed: ' . $e->getMessage());
            }
        }
    }

    private function log(string $level, string $message, ?array $context = null): void {
        blc_log($message, $level);

        if (isset($this->scanId)) {
            $stmt = $this->db->prepare('INSERT INTO scan_log (scan_id, level, message, context) VALUES (?, ?, ?, ?)');
            $stmt->execute([$this->scanId, $level, $message, $context ? json_encode($context) : null]);
        }
    }

    public function getScanId(): int {
        return $this->scanId;
    }
}
