<?php
/**
 * Broken Link Checker - Main Entry Point
 */

define('BLC_ROOT', __DIR__);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// Run migrations on first access
Database::runMigrations();

// Seed sites on first run
$db = Database::getInstance();
$siteCount = $db->query('SELECT COUNT(*) FROM sites')->fetchColumn();
if ($siteCount == 0) {
    $defaultSites = require __DIR__ . '/config/sites.php';
    $stmt = $db->prepare('INSERT INTO sites (name, url, is_wordpress) VALUES (?, ?, ?)');
    $schedStmt = $db->prepare('INSERT INTO scan_schedules (site_id, frequency, hour, minute) VALUES (?, \'weekly\', 2, 0)');
    foreach ($defaultSites as $site) {
        $stmt->execute([$site['name'], $site['url'], $site['is_wordpress']]);
        $schedStmt->execute([$db->lastInsertId()]);
    }
}

// WordPress authentication
require_once __DIR__ . '/includes/auth.php';
$auth = blc_require_auth();

// Route to requested page
$page = $_GET['page'] ?? 'dashboard';
$allowedPages = ['dashboard', 'site-detail', 'scan-history', 'ignore-list', 'settings', 'trends'];

if (!in_array($page, $allowedPages)) {
    $page = 'dashboard';
}

$pageFile = __DIR__ . '/pages/' . $page . '.php';
if (!file_exists($pageFile)) {
    $page = 'dashboard';
    $pageFile = __DIR__ . '/pages/dashboard.php';
}

// Buffer page content
ob_start();
include $pageFile;
$content = ob_get_clean();

// Render layout
include __DIR__ . '/templates/layout.php';
