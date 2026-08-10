<?php
// Cron: Check for and launch scheduled scans
// Run via crontab every 5 minutes:
// /usr/bin/php /path/to/broken-links/cron/run-scheduled.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/functions.php';
require_once BLC_ROOT . '/classes/ScanScheduler.php';

Database::runMigrations();

$db = Database::getInstance();
$scheduler = new ScanScheduler($db);

blc_log('Cron: Checking for scheduled scans...');

// Check if any scan is already running
if ($scheduler->isAnyScanRunning()) {
    blc_log('Cron: A scan is already running, skipping');
    exit(0);
}

$dueScans = $scheduler->getDueScans();

if (empty($dueScans)) {
    blc_log('Cron: No scans due');
    exit(0);
}

// Process only the first due scan
$schedule = $dueScans[0];
$siteId = $schedule['site_id'];

blc_log("Cron: Launching scheduled scan for site {$schedule['site_name']} (ID: {$siteId})");

// Update next run time
$scheduler->updateNextRun($schedule['id'], $schedule);

// Create scan record
$stmt = $db->prepare("INSERT INTO scans (site_id, status, trigger_type, started_at) VALUES (?, 'pending', 'scheduled', datetime('now'))");
$stmt->execute([$siteId]);
$scanId = (int) $db->lastInsertId();

// Launch worker in background
$phpBinary = PHP_BINARY ?: '/usr/bin/php';
$workerScript = BLC_ROOT . '/cron/scan-worker.php';
$logFile = BLC_ROOT . '/data/logs/scanner.log';
$cmd = sprintf(
    '%s %s --site-id=%d --scan-id=%d >> %s 2>&1 &',
    escapeshellarg($phpBinary),
    escapeshellarg($workerScript),
    $siteId,
    $scanId,
    escapeshellarg($logFile)
);

exec($cmd);

blc_log("Cron: Worker launched for scan #{$scanId}");
