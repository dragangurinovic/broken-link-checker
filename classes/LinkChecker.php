<?php

require_once __DIR__ . '/BlockDetector.php';

class LinkChecker {
    private int $timeout;
    private int $connectTimeout;

    public function __construct(int $timeout = 30, int $connectTimeout = 10) {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public function check(string $url, array $headers = [], bool $headFirst = true): array {
        $result = [
            'http_status' => null,
            'final_url' => null,
            'redirect_count' => 0,
            'response_time_ms' => null,
            'content_type' => null,
            'status_category' => 'broken',
            'block_type' => null,
            'error_message' => null,
            'response_headers' => [],
            'response_body' => '',
        ];

        if ($headFirst) {
            $headResult = $this->doRequest($url, $headers, 'HEAD');

            if ($headResult['http_status'] !== null && !in_array($headResult['http_status'], [405, 501])) {
                return $this->classifyResult(array_merge($result, $headResult));
            }
        }

        $getResult = $this->doRequest($url, $headers, 'GET');
        return $this->classifyResult(array_merge($result, $getResult));
    }

    private function doRequest(string $url, array $headers, string $method): array {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }

        $responseHeaders = [];
        $opts[CURLOPT_HEADERFUNCTION] = function ($ch, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        };

        curl_setopt_array($ch, $opts);

        $startTime = microtime(true);
        $body = curl_exec($ch);
        $elapsed = (microtime(true) - $startTime) * 1000;

        $result = [
            'http_status' => null,
            'final_url' => null,
            'redirect_count' => 0,
            'response_time_ms' => (int) $elapsed,
            'content_type' => null,
            'error_message' => null,
            'response_headers' => $responseHeaders,
            'response_body' => is_string($body) ? substr($body, 0, 50000) : '',
        ];

        if (curl_errno($ch)) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            $result['error_message'] = $error;

            if (in_array($errno, [CURLE_OPERATION_TIMEDOUT, CURLE_OPERATION_TIMEOUTED ?? 28])) {
                $result['status_category'] = 'timeout';
            } elseif (in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER, 35, 51, 58, 59, 60, 77, 82, 83])) {
                $result['error_message'] = 'SSL Error: ' . $error;
            } elseif (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, 6])) {
                $result['error_message'] = 'DNS resolution failed';
            } elseif (in_array($errno, [CURLE_COULDNT_CONNECT, 7])) {
                $result['error_message'] = 'Connection refused';
            }

            return $result;
        }

        $result['http_status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result['final_url'] = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $result['redirect_count'] = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        $result['content_type'] = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: null;

        curl_close($ch);

        return $result;
    }

    private function classifyResult(array $result): array {
        $status = $result['http_status'];

        if ($status === null) {
            $result['status_category'] = $result['status_category'] ?? 'broken';
            return $result;
        }

        if ($status >= 200 && $status < 400) {
            $result['status_category'] = 'ok';
        } elseif ($status === 404 || $status === 410) {
            $result['status_category'] = 'broken';
        } elseif ($status >= 500) {
            $blockInfo = BlockDetector::analyze($status, $result['response_headers'], $result['response_body']);
            if ($blockInfo['is_blocked']) {
                $result['status_category'] = 'blocked';
                $result['block_type'] = $blockInfo['block_type'];
            } else {
                $result['status_category'] = 'warning';
                $result['error_message'] = 'Server error: HTTP ' . $status;
            }
        } elseif ($status === 403 || $status === 429 || $status === 401) {
            $blockInfo = BlockDetector::analyze($status, $result['response_headers'], $result['response_body']);
            $result['status_category'] = 'blocked';
            $result['block_type'] = $blockInfo['block_type'];
        } else {
            $result['status_category'] = 'warning';
            $result['error_message'] = 'Unexpected status: HTTP ' . $status;
        }

        // Clean up - don't store large body in DB
        unset($result['response_body']);
        unset($result['response_headers']);

        return $result;
    }

    public function createCurlHandle(string $url, array $headers, bool $headOnly = false): \CurlHandle {
        $ch = curl_init();
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($headOnly) {
            $opts[CURLOPT_NOBODY] = true;
        }

        curl_setopt_array($ch, $opts);
        return $ch;
    }
}
