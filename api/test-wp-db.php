<?php
/**
 * API: Test WordPress database connection
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';
require_once BLC_ROOT . '/classes/WpDatabaseReader.php';

blc_require_auth_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    blc_json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

$config = [
    'url' => '',
    'wp_db_host' => $input['host'] ?? 'localhost',
    'wp_db_name' => $input['name'] ?? '',
    'wp_db_user' => $input['user'] ?? '',
    'wp_db_pass' => $input['pass'] ?? '',
    'wp_table_prefix' => $input['prefix'] ?? 'wp_',
];

if (empty($config['wp_db_name']) || empty($config['wp_db_user'])) {
    blc_json_response(['success' => false, 'error' => 'Database name and user are required']);
}

$result = WpDatabaseReader::testConnection($config);
blc_json_response($result);
