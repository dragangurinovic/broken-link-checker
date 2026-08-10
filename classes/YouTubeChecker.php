<?php

class YouTubeChecker {
    public static function check(string $url): array {
        $videoId = self::extractVideoId($url);
        if (empty($videoId)) {
            return [
                'exists' => null,
                'error' => 'Could not extract video ID',
            ];
        }

        $oembedUrl = 'https://www.youtube.com/oembed?url=' . urlencode('https://www.youtube.com/watch?v=' . $videoId) . '&format=json';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $oembedUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BrokenLinkChecker/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!empty($error)) {
            return ['exists' => null, 'error' => $error];
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return [
                'exists' => true,
                'title' => $data['title'] ?? '',
                'author' => $data['author_name'] ?? '',
            ];
        }

        if ($httpCode === 404 || $httpCode === 401) {
            return [
                'exists' => false,
                'error' => 'Video not available (HTTP ' . $httpCode . ')',
            ];
        }

        return [
            'exists' => null,
            'error' => 'Unexpected response: HTTP ' . $httpCode,
        ];
    }

    private static function extractVideoId(string $url): string {
        if (preg_match('#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/|youtube\.com/v/)([a-zA-Z0-9_-]{11})#', $url, $m)) {
            return $m[1];
        }
        return '';
    }
}
