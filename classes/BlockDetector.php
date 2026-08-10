<?php

class BlockDetector {
    private static array $captchaPatterns = [
        'captcha',
        'recaptcha',
        'hcaptcha',
        'cf-challenge',
        'challenge-platform',
        'verify you are human',
        'verify you are not a robot',
        'access denied',
        'just a moment',
        'checking your browser',
        'attention required',
        'cloudflare',
        'sucuri',
        'incapsula',
        'ddos protection',
        'bot detection',
        'are you a robot',
    ];

    public static function analyze(int $httpStatus, array $headers, string $body): array {
        $result = [
            'is_blocked' => false,
            'block_type' => null,
            'confidence' => 0,
        ];

        if ($httpStatus === 403) {
            if (self::hasHeader($headers, 'cf-ray') || self::hasHeader($headers, 'cf-cache-status')) {
                $result['is_blocked'] = true;
                $result['block_type'] = 'waf';
                $result['confidence'] = 80;
            }

            if (self::bodyContainsPatterns($body)) {
                $result['is_blocked'] = true;
                $result['block_type'] = 'captcha';
                $result['confidence'] = 90;
            }

            if (!$result['is_blocked']) {
                $result['is_blocked'] = true;
                $result['block_type'] = 'waf';
                $result['confidence'] = 60;
            }
        }

        if ($httpStatus === 429) {
            $result['is_blocked'] = true;
            $result['block_type'] = 'rate_limited';
            $result['confidence'] = 95;
        }

        if ($httpStatus === 503 && self::bodyContainsPatterns($body)) {
            $result['is_blocked'] = true;
            $result['block_type'] = 'waf';
            $result['confidence'] = 85;
        }

        if ($httpStatus === 200 && self::bodyContainsPatterns($body)) {
            if (strlen($body) < 10000 && self::looksLikeChallengePage($body)) {
                $result['is_blocked'] = true;
                $result['block_type'] = 'captcha';
                $result['confidence'] = 70;
            }
        }

        if ($httpStatus === 401) {
            $result['is_blocked'] = true;
            $result['block_type'] = 'login_required';
            $result['confidence'] = 90;
        }

        return $result;
    }

    private static function hasHeader(array $headers, string $name): bool {
        $name = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) return true;
        }
        return false;
    }

    private static function bodyContainsPatterns(string $body): bool {
        $bodyLower = strtolower($body);
        foreach (self::$captchaPatterns as $pattern) {
            if (str_contains($bodyLower, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private static function looksLikeChallengePage(string $body): bool {
        $bodyLower = strtolower($body);
        $signals = 0;

        if (str_contains($bodyLower, '<form')) $signals++;
        if (str_contains($bodyLower, 'challenge')) $signals++;
        if (str_contains($bodyLower, 'captcha')) $signals++;
        if (str_contains($bodyLower, 'javascript')) $signals++;
        if (preg_match('/\b(verify|checking|wait|moment)\b/', $bodyLower)) $signals++;

        $textContent = strip_tags($body);
        if (strlen(trim($textContent)) < 500) $signals++;

        return $signals >= 3;
    }
}
