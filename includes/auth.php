<?php
/**
 * WordPress authentication bridge
 * Verifies the visitor is a logged-in WordPress administrator
 */

if (!defined('BLC_ROOT')) {
    define('BLC_ROOT', dirname(__DIR__));
}
require_once BLC_ROOT . '/config/config.php';

function blc_check_auth(): array {
    // If WordPress is already loaded (e.g., from index.php routing through WP),
    // skip re-loading wp-load.php
    if (function_exists('is_user_logged_in')) {
        return blc_validate_wp_user();
    }

    $wpLoadPath = BLC_WP_PATH . '/wp-load.php';

    if (!file_exists($wpLoadPath)) {
        return [
            'authenticated' => false,
            'error' => 'WordPress not found at: ' . BLC_WP_PATH . ' — update BLC_WP_PATH in config/config.php',
        ];
    }

    if (!defined('ABSPATH')) {
        define('ABSPATH', BLC_WP_PATH . '/');
    }

    ob_start();
    try {
        require_once $wpLoadPath;
    } catch (\Throwable $e) {
        ob_end_clean();
        return [
            'authenticated' => false,
            'error' => 'Failed to load WordPress: ' . $e->getMessage(),
        ];
    }
    ob_end_clean();

    return blc_validate_wp_user();
}

function blc_validate_wp_user(): array {

    if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
        return [
            'authenticated' => false,
            'error' => 'not_logged_in',
            'login_url' => wp_login_url(BLC_APP_URL),
        ];
    }

    if (!current_user_can('manage_options')) {
        return [
            'authenticated' => false,
            'error' => 'insufficient_permissions',
        ];
    }

    $user = wp_get_current_user();
    return [
        'authenticated' => true,
        'user' => [
            'id' => $user->ID,
            'login' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
        ],
    ];
}

function blc_require_auth(): array {
    // Skip auth for CLI (cron jobs)
    if (php_sapi_name() === 'cli') {
        return [
            'authenticated' => true,
            'user' => ['login' => 'cli', 'display_name' => 'System', 'email' => ''],
        ];
    }

    $auth = blc_check_auth();

    if (!$auth['authenticated']) {
        if (isset($auth['login_url'])) {
            header('Location: ' . $auth['login_url']);
            exit;
        }

        if ($auth['error'] === 'insufficient_permissions') {
            http_response_code(403);
            echo '<!DOCTYPE html><html><head><title>Access Denied</title></head><body>';
            echo '<h1>Access Denied</h1><p>You need administrator privileges to access this tool.</p>';
            echo '<p><a href="' . htmlspecialchars(BLC_WP_PATH) . '">Return to site</a></p>';
            echo '</body></html>';
            exit;
        }

        http_response_code(500);
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
        echo '<h1>Configuration Error</h1><p>' . htmlspecialchars($auth['error']) . '</p>';
        echo '</body></html>';
        exit;
    }

    return $auth;
}

function blc_require_auth_api(): array {
    if (php_sapi_name() === 'cli') {
        return ['authenticated' => true, 'user' => ['login' => 'cli', 'display_name' => 'System', 'email' => '']];
    }

    $auth = blc_check_auth();

    if (!$auth['authenticated']) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => $auth['error'] ?? 'Unauthorized']);
        exit;
    }

    return $auth;
}
