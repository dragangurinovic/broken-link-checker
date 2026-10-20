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

$statusParam = $_GET['status'] ?? null;
$statusCategories = null;
if ($statusParam) {
    $statusCategories = array_map('trim', explode(',', $statusParam));
}

$filters = [
    'scan_id' => $_GET['scan_id'] ?? null,
    'site_id' => $_GET['site_id'] ?? null,
    'status_category' => $statusCategories,
];

$db = Database::getInstance();
CsvExporter::export($db, $filters);
