<?php
/**
 * API: Read/write settings
 */

define('BLC_ROOT', dirname(__DIR__));
require_once BLC_ROOT . '/includes/db.php';
require_once BLC_ROOT . '/includes/auth.php';
require_once BLC_ROOT . '/includes/functions.php';

blc_require_auth_api();

$db = Database::getInstance();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = [];
    $rows = $db->query('SELECT key, value, description FROM settings')->fetchAll();
    foreach ($rows as $row) {
        $settings[$row['key']] = [
            'value' => $row['value'],
            'description' => $row['description'],
        ];
    }
    blc_json_response(['settings' => $settings]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input) || !is_array($input)) {
        blc_json_response(['error' => 'Invalid input'], 400);
    }

    foreach ($input as $key => $value) {
        if (is_string($key) && is_string($value)) {
            Database::setSetting($key, $value);
        }
    }

    blc_json_response(['success' => true]);
}

blc_json_response(['error' => 'Method not allowed'], 405);
