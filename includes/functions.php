<?php
/**
 * Shared helper functions
 */

function blc_time_ago(string $datetime): string {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);

    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'just now';
}

function blc_format_duration(int $seconds): string {
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return $h . 'h ' . $m . 'm';
}

function blc_status_badge(string $category): string {
    $classes = [
        'ok' => 'badge-success',
        'broken' => 'badge-danger',
        'warning' => 'badge-warning',
        'timeout' => 'badge-warning',
        'blocked' => 'badge-info',
        'ignored' => 'badge-secondary',
        'unchecked' => 'badge-light',
    ];
    $class = $classes[$category] ?? 'badge-light';
    return '<span class="badge ' . $class . '">' . htmlspecialchars(ucfirst($category)) . '</span>';
}

function blc_health_color(float $score): string {
    if ($score >= 95) return '#22c55e';
    if ($score >= 85) return '#eab308';
    if ($score >= 70) return '#f97316';
    return '#ef4444';
}

function blc_truncate(string $text, int $length = 60): string {
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . '...';
}

function blc_sanitize_url(string $url): string {
    $url = trim($url);
    $url = filter_var($url, FILTER_SANITIZE_URL);
    return $url ?: '';
}

function blc_is_valid_url(string $url): bool {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

function blc_normalize_url(string $url, string $baseUrl = ''): string {
    $url = trim($url);

    if (empty($url) || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') ||
        str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:') ||
        str_starts_with($url, 'data:')) {
        return '';
    }

    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }

    if (!preg_match('#^https?://#i', $url) && !empty($baseUrl)) {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $basePath = $parsed['path'] ?? '/';

        if (str_starts_with($url, '/')) {
            $url = $scheme . '://' . $host . $url;
        } else {
            $dir = rtrim(dirname($basePath), '/');
            $url = $scheme . '://' . $host . $dir . '/' . $url;
        }
    }

    $url = preg_replace('/#.*$/', '', $url);
    $url = rtrim($url, '/');

    return $url;
}

function blc_get_domain(string $url): string {
    $parsed = parse_url($url);
    return $parsed['host'] ?? '';
}

function blc_is_youtube_url(string $url): bool {
    return (bool) preg_match('#(youtube\.com|youtu\.be)#i', $url);
}

function blc_extract_youtube_id(string $url): string {
    if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/v/)([a-zA-Z0-9_-]{11})#', $url, $m)) {
        return $m[1];
    }
    return '';
}

function blc_json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function blc_log(string $message, string $level = 'info'): void {
    $logFile = BLC_LOG_FILE;
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] [$level] $message\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
