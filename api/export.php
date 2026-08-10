<?php
/**
 * API: Export links as CSV
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';
require_once BLC_ROOT . '/classes/CsvExporter.php';

blc_require_auth_api();

$filters = [
    'scan_id' => $_GET['scan_id'] ?? null,
    'site_id' => $_GET['site_id'] ?? null,
    'status_category' => $_GET['status'] ?? null,
];

$db = Database::getInstance();
CsvExporter::export($db, $filters);
