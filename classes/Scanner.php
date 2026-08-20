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
require_once __DIR__ . '/WpDatabaseReader.php';

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
            if (!empty($this->site['wp_db_name']) && !empty($this->site['wp_db_user'])) {
                $this->crawlFromDatabase();
            } else {
                $this->crawl();
            }
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
            $pageId = $this->savePageRecord($url, $httpStatus ?: 0, $contentType, $responseTime, $depth);

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

                if (isset($this->checkedTargets[$targetUrl])) {
                    $linksBuffer[] = $this->buildLinkRecord($pageId, $url, $targetUrl, $link, $isInternal, $this->checkedTargets[$targetUrl]);
                } else {
                    $linkResult = $this->checkLink($targetUrl, $link['type']);
                    $this->checkedTargets[$targetUrl] = $linkResult;
                    $linksBuffer[] = $this->buildLinkRecord($pageId, $url, $targetUrl, $link, $isInternal, $linkResult);
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

    private function crawlFromDatabase(): void {
        $wpReader = new WpDatabaseReader($this->site);
        $totalPosts = $wpReader->countPublished();
        $this->log('info', "Direct DB mode: {$totalPosts} published posts/pages to process");

        $stmt = $this->db->prepare("UPDATE scans SET pages_total = ? WHERE id = ?");
        $stmt->execute([$totalPosts, $this->scanId]);

        $siteDomain = blc_get_domain($this->site['url']);
        $siteUrl = rtrim($this->site['url'], '/');

        // ── PHASE 1: Extract all links from database (no HTTP) ──
        $this->log('info', 'Phase 1/3: Extracting links from all posts...');

        $publishedUrls = [];
        $uniqueLinks = [];
        $batchSize = 200;
        $pagesProcessed = 0;
        $offset = 0;

        while (!$this->cancelled) {
            $posts = $wpReader->getPublishedContent($batchSize, $offset);
            if (empty($posts)) break;
            $offset += $batchSize;

            $this->db->beginTransaction();
            try {
                foreach ($posts as $post) {
                    if ($this->cancelled) break;

                    $pageUrl = $wpReader->getPermalink($post);
                    if (isset($this->visitedUrls[$pageUrl])) continue;
                    $this->visitedUrls[$pageUrl] = true;

                    $publishedUrls[$this->normalizeForLookup($pageUrl)] = true;

                    $pageId = $this->savePageRecord($pageUrl, 200, 'text/html', 0, 0);
                    $pagesProcessed++;

                    $html = $wpReader->wrapContent($post['post_content']);
                    $extractedLinks = LinkExtractor::extract($html, $pageUrl, [
                        'check_images' => (bool) $this->site['check_images'],
                        'check_youtube' => (bool) $this->site['check_youtube'],
                    ]);

                    foreach ($extractedLinks as $link) {
                        $targetUrl = $link['url'];
                        if (empty($targetUrl)) continue;

                        $isInternal = $this->isInternalUrl($targetUrl, $siteDomain);
                        if (!$isInternal && !$this->site['crawl_external']) continue;

                        if (!isset($uniqueLinks[$targetUrl])) {
                            $uniqueLinks[$targetUrl] = [
                                'is_internal' => $isInternal,
                                'type' => $link['type'],
                                'sources' => [],
                            ];
                        }
                        $uniqueLinks[$targetUrl]['sources'][] = [
                            'page_id' => $pageId,
                            'page_url' => $pageUrl,
                            'text' => $link['text'],
                            'type' => $link['type'],
                        ];
                    }
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $this->log('error', 'Phase 1 batch write failed: ' . $e->getMessage());
            }

            $this->updateProgress($pagesProcessed);
            $this->log('info', "Phase 1/3: {$pagesProcessed}/{$totalPosts} pages, " . count($uniqueLinks) . " unique links found");

            $stmt = $this->db->prepare("SELECT status FROM scans WHERE id = ?");
            $stmt->execute([$this->scanId]);
            if ($stmt->fetchColumn() === 'cancelled') {
                $this->cancelled = true;
                $this->log('info', 'Scan cancelled by user');
            }
        }

        $this->updateProgress($pagesProcessed);
        $publishedUrls[$this->normalizeForLookup($siteUrl)] = true;
        $publishedUrls[$this->normalizeForLookup($siteUrl . '/')] = true;

        $internalLinks = [];
        $externalLinks = [];
        foreach ($uniqueLinks as $url => $info) {
            if ($info['is_internal']) {
                $internalLinks[$url] = $info;
            } else {
                $externalLinks[$url] = $info;
            }
        }
        unset($uniqueLinks);

        $this->log('info', "Phase 1/3 done: {$pagesProcessed} pages, " . count($internalLinks) . " internal + " . count($externalLinks) . " external unique links");

        if ($this->cancelled) return;

        // ── PHASE 2: Verify internal links via database (no HTTP) ──
        $this->log('info', 'Phase 2/3: Verifying ' . count($internalLinks) . ' internal links via database...');

        $linksBuffer = [];
        $checkedCount = 0;

        foreach ($internalLinks as $url => $info) {
            if ($this->cancelled) break;

            $result = $this->verifyInternalLink($url, $publishedUrls);
            $this->checkedTargets[$url] = $result;

            foreach ($info['sources'] as $source) {
                $linksBuffer[] = $this->buildLinkRecord(
                    $source['page_id'], $source['page_url'], $url,
                    ['text' => $source['text'], 'type' => $source['type']],
                    true, $result
                );
            }

            $checkedCount++;
            if (count($linksBuffer) >= 500) {
                $this->flushLinks($linksBuffer);
                $linksBuffer = [];
            }
        }

        if (!empty($linksBuffer)) {
            $this->flushLinks($linksBuffer);
            $linksBuffer = [];
        }

        unset($internalLinks, $publishedUrls);
        $this->log('info', "Phase 2/3 done: {$checkedCount} internal links verified (no HTTP)");

        if ($this->cancelled) return;

        // ── PHASE 3: Check external links via HTTP (concurrent) ──
        $totalExternal = count($externalLinks);
        $this->log('info', "Phase 3/3: Checking {$totalExternal} external links via HTTP...");

        $checkedCount = 0;
        $concurrency = max(10, $this->concurrentRequests);

        $preChecked = [];
        $httpCheckUrls = [];
        foreach ($externalLinks as $url => $info) {
            if ($this->isIgnored($url)) {
                $preChecked[$url] = [
                    'http_status' => null, 'status_category' => 'ignored',
                    'response_time_ms' => null, 'final_url' => null,
                    'redirect_count' => 0, 'block_type' => null,
                    'error_message' => 'Ignored by rule',
                ];
                continue;
            }
            if (($info['type'] === 'youtube') && blc_is_youtube_url($url)) {
                $ytResult = YouTubeChecker::check($url);
                if ($ytResult['exists'] === true) {
                    $preChecked[$url] = [
                        'http_status' => 200, 'status_category' => 'ok',
                        'response_time_ms' => null, 'final_url' => $url,
                        'redirect_count' => 0, 'block_type' => null,
                        'error_message' => null,
                    ];
                    continue;
                } elseif ($ytResult['exists'] === false) {
                    $preChecked[$url] = [
                        'http_status' => 404, 'status_category' => 'broken',
                        'response_time_ms' => null, 'final_url' => $url,
                        'redirect_count' => 0, 'block_type' => null,
                        'error_message' => $ytResult['error'] ?? 'Video not available',
                    ];
                    continue;
                }
            }
            $httpCheckUrls[] = $url;
        }

        foreach ($preChecked as $url => $result) {
            $this->checkedTargets[$url] = $result;
            foreach ($externalLinks[$url]['sources'] as $source) {
                $linksBuffer[] = $this->buildLinkRecord(
                    $source['page_id'], $source['page_url'], $url,
                    ['text' => $source['text'], 'type' => $source['type']],
                    false, $result
                );
            }
            $checkedCount++;
        }
        if (count($linksBuffer) >= 500) {
            $this->flushLinks($linksBuffer);
            $linksBuffer = [];
        }

        $batches = array_chunk($httpCheckUrls, $concurrency);
        foreach ($batches as $batch) {
            if ($this->cancelled) break;

            $batchResults = $this->checkUrlBatchConcurrent($batch);

            foreach ($batchResults as $url => $result) {
                $this->checkedTargets[$url] = $result;
                foreach ($externalLinks[$url]['sources'] as $source) {
                    $linksBuffer[] = $this->buildLinkRecord(
                        $source['page_id'], $source['page_url'], $url,
                        ['text' => $source['text'], 'type' => $source['type']],
                        false, $result
                    );
                }
            }

            $checkedCount += count($batch);
            if (count($linksBuffer) >= 500) {
                $this->flushLinks($linksBuffer);
                $linksBuffer = [];
            }

            $this->updateProgress($pagesProcessed);
            if ($checkedCount % ($concurrency * 3) < $concurrency) {
                $this->log('info', "Phase 3/3: {$checkedCount}/{$totalExternal} external links checked");

                $stmt = $this->db->prepare("SELECT status FROM scans WHERE id = ?");
                $stmt->execute([$this->scanId]);
                if ($stmt->fetchColumn() === 'cancelled') {
                    $this->cancelled = true;
                    $this->log('info', 'Scan cancelled by user');
                }
            }
        }

        if (!empty($linksBuffer)) {
            $this->flushLinks($linksBuffer);
        }

        $this->log('info', "Phase 3/3 done: {$checkedCount} external links checked via HTTP");
    }

    private function verifyInternalLink(string $url, array $publishedUrls): array {
        if ($this->isIgnored($url)) {
            return [
                'http_status' => null, 'status_category' => 'ignored',
                'response_time_ms' => null, 'final_url' => null,
                'redirect_count' => 0, 'block_type' => null,
                'error_message' => 'Ignored by rule',
            ];
        }

        $ok = [
            'http_status' => 200, 'status_category' => 'ok',
            'response_time_ms' => 0, 'final_url' => $url,
            'redirect_count' => 0, 'block_type' => null,
            'error_message' => null,
        ];

        $normalized = $this->normalizeForLookup($url);
        if (isset($publishedUrls[$normalized])) {
            return $ok;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        if (preg_match('#^/(wp-admin|wp-login|wp-includes|feed|xmlrpc|wp-json|wp-cron)#i', $path)) {
            return $ok;
        }

        if (preg_match('#^/(category|tag|author|page|\d{4}/\d{2}(/\d{2})?)(/|$)#i', $path)) {
            return $ok;
        }

        if (str_contains($path, '/wp-content/')) {
            return $ok;
        }

        if (preg_match('#\.(jpg|jpeg|png|gif|svg|webp|ico|pdf|css|js|woff2?|ttf|eot|mp[34]|zip|xml|txt|html?)(\?.*)?$#i', $path)) {
            return $ok;
        }

        if ($path === '/' || $path === '') {
            return $ok;
        }

        $urlNoQuery = strtok($url, '?');
        $normNoQuery = $this->normalizeForLookup($urlNoQuery);
        if (isset($publishedUrls[$normNoQuery])) {
            return $ok;
        }

        return [
            'http_status' => 404, 'status_category' => 'broken',
            'response_time_ms' => 0, 'final_url' => $url,
            'redirect_count' => 0, 'block_type' => null,
            'error_message' => 'Not found in published content',
        ];
    }

    private function normalizeForLookup(string $url): string {
        $url = preg_replace('/#.*$/', '', $url);
        $url = strtok($url, '?');
        return rtrim($url, '/');
    }

    private function checkUrlBatchConcurrent(array $urls): array {
        $results = [];

        $rawPass1 = $this->curlMultiBatch($urls, true, false);

        $needGet = [];
        $needRetry = [];

        foreach ($rawPass1 as $url => $raw) {
            $status = $raw['http_status'];

            if ($raw['error']) {
                if ($raw['is_timeout']) {
                    $needRetry[] = $url;
                } else {
                    $results[$url] = [
                        'http_status' => null, 'status_category' => 'broken',
                        'response_time_ms' => $raw['response_time_ms'],
                        'final_url' => $raw['final_url'], 'redirect_count' => 0,
                        'block_type' => null,
                        'error_message' => $raw['error'],
                    ];
                }
            } elseif (in_array($status, [405, 501])) {
                $needGet[] = $url;
            } elseif ($status >= 200 && $status < 400) {
                $results[$url] = [
                    'http_status' => $status, 'status_category' => 'ok',
                    'response_time_ms' => $raw['response_time_ms'],
                    'final_url' => $raw['final_url'], 'redirect_count' => $raw['redirect_count'],
                    'block_type' => null, 'error_message' => null,
                ];
            } elseif ($status === 404 || $status === 410) {
                $results[$url] = [
                    'http_status' => $status, 'status_category' => 'broken',
                    'response_time_ms' => $raw['response_time_ms'],
                    'final_url' => $raw['final_url'], 'redirect_count' => $raw['redirect_count'],
                    'block_type' => null, 'error_message' => null,
                ];
            } elseif (in_array($status, [403, 429, 401]) || $status >= 500) {
                $needRetry[] = $url;
            } else {
                $results[$url] = [
                    'http_status' => $status, 'status_category' => 'warning',
                    'response_time_ms' => $raw['response_time_ms'],
                    'final_url' => $raw['final_url'], 'redirect_count' => $raw['redirect_count'],
                    'block_type' => null,
                    'error_message' => 'HTTP ' . $status,
                ];
            }
        }

        if (!empty($needGet)) {
            $rawGet = $this->curlMultiBatch($needGet, false, false);
            foreach ($rawGet as $url => $raw) {
                $results[$url] = $this->classifyRawResult($raw, false);
            }
        }

        if (!empty($needRetry)) {
            $rawRetry = $this->curlMultiBatch($needRetry, false, true);
            foreach ($rawRetry as $url => $raw) {
                $results[$url] = $this->classifyRawResult($raw, true);
            }
        }

        return $results;
    }

    private function curlMultiBatch(array $urls, bool $headOnly, bool $browserUa): array {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($urls as $url) {
            $headers = $this->uaRotator->getHeaders($browserUa);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => $headers,
            ]);
            if ($headOnly) {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            }
            $handles[$url] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active > 0) {
                curl_multi_select($mh, 0.2);
            }
        } while ($active > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $url => $ch) {
            $errno = curl_errno($ch);
            $results[$url] = [
                'http_status' => $errno ? null : (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'final_url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url,
                'redirect_count' => (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
                'response_time_ms' => (int) (curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
                'error' => $errno ? (curl_error($ch) ?: 'Connection failed') : null,
                'is_timeout' => in_array($errno, [CURLE_OPERATION_TIMEDOUT, 28]),
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    private function classifyRawResult(array $raw, bool $isRetry): array {
        if ($raw['error']) {
            return [
                'http_status' => null,
                'status_category' => $raw['is_timeout'] ? 'timeout' : 'broken',
                'response_time_ms' => $raw['response_time_ms'],
                'final_url' => $raw['final_url'], 'redirect_count' => $raw['redirect_count'],
                'block_type' => null, 'error_message' => $raw['error'],
            ];
        }

        $status = $raw['http_status'];

        if ($status >= 200 && $status < 400) {
            return [
                'http_status' => $status, 'status_category' => 'ok',
                'response_time_ms' => $raw['response_time_ms'],
                'final_url' => $raw['final_url'], 'redirect_count' => $raw['redirect_count'],
                'block_type' => $isRetry ? 'waf' : null, 'error_message' => null,
            ];
        }

        if ($status === 404 || $status === 410) {
            return [
                'http_status' => $status, 'status_category' => 'broken',
                'response_time_ms' => $raw['response_time_ms'],
                'final_url' => $raw['final_url'], 'redirect_count' => $raw['redirect_count'],
                'block_type' => null, 'error_message' => null,
            ];
        }

        if (in_array($status, [403, 429, 401]) || $status >= 500) {
            return [
                'http_status' => $status, 'status_category' => 'blocked',
                'response_time_ms' => $raw['response_time_ms'],
                'final_url' => $raw['final_url'], 'redirect_count' => $raw['redirect_count'],
                'block_type' => 'waf', 'error_message' => null,
            ];
        }

        return [
            'http_status' => $status, 'status_category' => 'warning',
            'response_time_ms' => $raw['response_time_ms'],
            'final_url' => $raw['final_url'], 'redirect_count' => $raw['redirect_count'],
            'block_type' => null, 'error_message' => 'HTTP ' . $status,
        ];
    }

    private function savePageRecord(string $url, int $httpStatus, string $contentType, int $responseTime, int $depth): int {
        $stmt = $this->db->prepare('INSERT INTO pages (scan_id, site_id, url, http_status, content_type, response_time_ms, crawl_depth) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$this->scanId, $this->siteId, $url, $httpStatus, $contentType, $responseTime, $depth]);
        return (int) $this->db->lastInsertId();
    }

    private function buildLinkRecord(int $pageId, string $pageUrl, string $targetUrl, array $link, bool $isInternal, array $result): array {
        return [
            'scan_id' => $this->scanId,
            'site_id' => $this->siteId,
            'source_page_id' => $pageId,
            'source_url' => $pageUrl,
            'target_url' => $targetUrl,
            'link_text' => $link['text'],
            'link_type' => $link['type'],
            'is_internal' => $isInternal ? 1 : 0,
            'status_category' => $result['status_category'],
            'http_status' => $result['http_status'],
            'response_time_ms' => $result['response_time_ms'],
            'final_url' => $result['final_url'],
            'redirect_count' => $result['redirect_count'],
            'block_type' => $result['block_type'],
            'error_message' => $result['error_message'],
        ];
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
