<?php
/**
 * Broken Link Checker - Configuration
 */

if (!defined('BLC_ROOT')) {
    define('BLC_ROOT', dirname(__DIR__));
}

// WordPress installation path - adjust for your server
define('BLC_WP_PATH', dirname(BLC_ROOT));

// Database
define('BLC_DB_PATH', BLC_ROOT . '/data/broken_links.db');

// App URL (used in notifications)
define('BLC_APP_URL', 'https://investor.fm/broken-links/');

// App version
define('BLC_VERSION', '1.0.0');

// Log file
define('BLC_LOG_FILE', BLC_ROOT . '/data/logs/scanner.log');

// Max memory for scanner (CLI)
define('BLC_SCANNER_MEMORY_LIMIT', '256M');

// Max execution time for scanner (CLI) - 2 hours
define('BLC_SCANNER_MAX_TIME', 7200);

// Timezone
define('BLC_TIMEZONE', 'America/New_York');

date_default_timezone_set(BLC_TIMEZONE);
