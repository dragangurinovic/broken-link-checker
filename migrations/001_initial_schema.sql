-- Sites
CREATE TABLE IF NOT EXISTS sites (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    url             TEXT NOT NULL UNIQUE,
    is_wordpress    INTEGER NOT NULL DEFAULT 0,
    crawl_internal  INTEGER NOT NULL DEFAULT 1,
    crawl_external  INTEGER NOT NULL DEFAULT 1,
    check_images    INTEGER NOT NULL DEFAULT 1,
    check_youtube   INTEGER NOT NULL DEFAULT 1,
    max_pages       INTEGER NOT NULL DEFAULT 5000,
    max_depth       INTEGER NOT NULL DEFAULT 10,
    rate_limit_ms   INTEGER NOT NULL DEFAULT 500,
    enabled         INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Scan schedules
CREATE TABLE IF NOT EXISTS scan_schedules (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id         INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    frequency       TEXT NOT NULL DEFAULT 'weekly',
    day_of_week     INTEGER DEFAULT 1,
    day_of_month    INTEGER DEFAULT 1,
    hour            INTEGER NOT NULL DEFAULT 2,
    minute          INTEGER NOT NULL DEFAULT 0,
    timezone        TEXT NOT NULL DEFAULT 'America/New_York',
    last_run_at     TEXT,
    next_run_at     TEXT,
    enabled         INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(site_id)
);

-- Scans
CREATE TABLE IF NOT EXISTS scans (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id         INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    status          TEXT NOT NULL DEFAULT 'pending',
    trigger_type    TEXT NOT NULL DEFAULT 'manual',
    pages_crawled   INTEGER NOT NULL DEFAULT 0,
    pages_total     INTEGER DEFAULT NULL,
    links_checked   INTEGER NOT NULL DEFAULT 0,
    links_broken    INTEGER NOT NULL DEFAULT 0,
    links_warning   INTEGER NOT NULL DEFAULT 0,
    links_ok        INTEGER NOT NULL DEFAULT 0,
    health_score    REAL DEFAULT NULL,
    duration_secs   INTEGER DEFAULT NULL,
    error_message   TEXT DEFAULT NULL,
    started_at      TEXT,
    completed_at    TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_scans_site_status ON scans(site_id, status);
CREATE INDEX IF NOT EXISTS idx_scans_site_created ON scans(site_id, created_at DESC);

-- Pages
CREATE TABLE IF NOT EXISTS pages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    scan_id         INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
    site_id         INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    url             TEXT NOT NULL,
    http_status     INTEGER DEFAULT NULL,
    content_type    TEXT DEFAULT NULL,
    response_time_ms INTEGER DEFAULT NULL,
    crawl_depth     INTEGER NOT NULL DEFAULT 0,
    discovered_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pages_scan ON pages(scan_id);
CREATE INDEX IF NOT EXISTS idx_pages_url ON pages(scan_id, url);

-- Links
CREATE TABLE IF NOT EXISTS links (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    scan_id         INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
    site_id         INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    source_page_id  INTEGER REFERENCES pages(id) ON DELETE CASCADE,
    source_url      TEXT NOT NULL,
    target_url      TEXT NOT NULL,
    link_text       TEXT DEFAULT NULL,
    link_type       TEXT NOT NULL DEFAULT 'anchor',
    is_internal     INTEGER NOT NULL DEFAULT 0,
    http_status     INTEGER DEFAULT NULL,
    final_url       TEXT DEFAULT NULL,
    redirect_count  INTEGER DEFAULT 0,
    response_time_ms INTEGER DEFAULT NULL,
    content_type    TEXT DEFAULT NULL,
    status_category TEXT NOT NULL DEFAULT 'unchecked',
    block_type      TEXT DEFAULT NULL,
    error_message   TEXT DEFAULT NULL,
    retry_count     INTEGER NOT NULL DEFAULT 0,
    checked_at      TEXT DEFAULT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_links_scan_status ON links(scan_id, status_category);
CREATE INDEX IF NOT EXISTS idx_links_target ON links(target_url);
CREATE INDEX IF NOT EXISTS idx_links_source ON links(source_url);

-- Ignored URLs
CREATE TABLE IF NOT EXISTS ignored_urls (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    pattern         TEXT NOT NULL,
    pattern_type    TEXT NOT NULL DEFAULT 'exact',
    reason          TEXT DEFAULT NULL,
    site_id         INTEGER DEFAULT NULL REFERENCES sites(id) ON DELETE CASCADE,
    created_by      TEXT DEFAULT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(pattern, site_id)
);

CREATE INDEX IF NOT EXISTS idx_ignored_site ON ignored_urls(site_id);

-- Settings
CREATE TABLE IF NOT EXISTS settings (
    key             TEXT PRIMARY KEY,
    value           TEXT NOT NULL,
    description     TEXT DEFAULT NULL,
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

INSERT OR IGNORE INTO settings (key, value, description) VALUES
    ('concurrent_requests',     '5',    'Max simultaneous cURL handles'),
    ('request_timeout_secs',    '30',   'Timeout per request in seconds'),
    ('connect_timeout_secs',    '10',   'Connection timeout in seconds'),
    ('max_retries',             '2',    'Retries for failed requests'),
    ('retry_delay_ms',          '2000', 'Delay between retries'),
    ('default_rate_limit_ms',   '500',  'Default ms between requests per domain'),
    ('max_concurrent_scans',    '1',    'Only one scan at a time'),
    ('notify_email_enabled',    '0',    'Enable email notifications'),
    ('notify_email_to',         '',     'Comma-separated email addresses'),
    ('notify_slack_enabled',    '0',    'Enable Slack notifications'),
    ('notify_slack_webhook',    '',     'Slack incoming webhook URL'),
    ('notify_slack_channel',    '',     'Slack channel or person'),
    ('notify_on_completion',    '1',    'Notify when scan completes'),
    ('notify_only_if_broken',   '1',    'Only notify if broken links found'),
    ('wp_path',                 '',     'Absolute path to WordPress installation'),
    ('scanner_log_level',       'info', 'Log level: debug, info, warn, error');

-- User agents
CREATE TABLE IF NOT EXISTS user_agents (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    agent_string    TEXT NOT NULL UNIQUE,
    agent_type      TEXT NOT NULL DEFAULT 'browser',
    enabled         INTEGER NOT NULL DEFAULT 1
);

INSERT OR IGNORE INTO user_agents (agent_string, agent_type) VALUES
    ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36', 'browser'),
    ('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36', 'browser'),
    ('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36', 'browser'),
    ('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0', 'browser'),
    ('Mozilla/5.0 (compatible; BrokenLinkChecker/1.0; +https://investor.fm)', 'bot');

-- Scan log
CREATE TABLE IF NOT EXISTS scan_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    scan_id         INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
    level           TEXT NOT NULL DEFAULT 'info',
    message         TEXT NOT NULL,
    context         TEXT DEFAULT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_scan_log_scan ON scan_log(scan_id, created_at);

-- Notification log
CREATE TABLE IF NOT EXISTS notification_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    scan_id         INTEGER REFERENCES scans(id) ON DELETE SET NULL,
    channel         TEXT NOT NULL,
    recipient       TEXT NOT NULL,
    subject         TEXT DEFAULT NULL,
    status          TEXT NOT NULL DEFAULT 'sent',
    error_message   TEXT DEFAULT NULL,
    sent_at         TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Trend snapshots
CREATE TABLE IF NOT EXISTS trend_snapshots (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id         INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    scan_id         INTEGER NOT NULL REFERENCES scans(id) ON DELETE CASCADE,
    snapshot_date   TEXT NOT NULL,
    total_links     INTEGER NOT NULL DEFAULT 0,
    broken_links    INTEGER NOT NULL DEFAULT 0,
    warning_links   INTEGER NOT NULL DEFAULT 0,
    blocked_links   INTEGER NOT NULL DEFAULT 0,
    ok_links        INTEGER NOT NULL DEFAULT 0,
    health_score    REAL NOT NULL DEFAULT 0,
    avg_response_ms INTEGER DEFAULT NULL,
    pages_crawled   INTEGER NOT NULL DEFAULT 0,
    UNIQUE(site_id, snapshot_date)
);

CREATE INDEX IF NOT EXISTS idx_trends_site_date ON trend_snapshots(site_id, snapshot_date DESC);
