<?php

class LinkExtractor {
    public static function extract(string $html, string $pageUrl, array $options = []): array {
        $links = [];
        $checkImages = $options['check_images'] ?? true;
        $checkYoutube = $options['check_youtube'] ?? true;

        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        // Extract <a> tags
        $anchors = $doc->getElementsByTagName('a');
        foreach ($anchors as $a) {
            $href = trim($a->getAttribute('href'));
            if (empty($href)) continue;

            $resolved = self::resolveUrl($href, $pageUrl);
            if (empty($resolved)) continue;

            $linkText = trim($a->textContent);
            $type = 'anchor';

            if ($checkYoutube && self::isYoutubeUrl($resolved)) {
                $type = 'youtube';
            }

            $links[] = [
                'url' => $resolved,
                'text' => mb_substr($linkText, 0, 255),
                'type' => $type,
            ];
        }

        // Extract <img> tags
        if ($checkImages) {
            $imgs = $doc->getElementsByTagName('img');
            foreach ($imgs as $img) {
                $src = trim($img->getAttribute('src'));
                if (empty($src)) continue;

                $resolved = self::resolveUrl($src, $pageUrl);
                if (empty($resolved)) continue;

                $alt = trim($img->getAttribute('alt'));
                $links[] = [
                    'url' => $resolved,
                    'text' => mb_substr($alt, 0, 255),
                    'type' => 'image',
                ];

                // Check srcset
                $srcset = $img->getAttribute('srcset');
                if (!empty($srcset)) {
                    foreach (self::parseSrcset($srcset) as $srcsetUrl) {
                        $resolved = self::resolveUrl($srcsetUrl, $pageUrl);
                        if (!empty($resolved)) {
                            $links[] = [
                                'url' => $resolved,
                                'text' => $alt,
                                'type' => 'image',
                            ];
                        }
                    }
                }
            }
        }

        // Extract <iframe> (YouTube embeds)
        if ($checkYoutube) {
            $iframes = $doc->getElementsByTagName('iframe');
            foreach ($iframes as $iframe) {
                $src = trim($iframe->getAttribute('src'));
                if (empty($src)) continue;

                $resolved = self::resolveUrl($src, $pageUrl);
                if (empty($resolved) || !self::isYoutubeUrl($resolved)) continue;

                $links[] = [
                    'url' => $resolved,
                    'text' => 'YouTube Embed',
                    'type' => 'youtube',
                ];
            }
        }

        // Extract <link> stylesheet
        $linkTags = $doc->getElementsByTagName('link');
        foreach ($linkTags as $link) {
            $rel = strtolower($link->getAttribute('rel'));
            if ($rel !== 'stylesheet') continue;

            $href = trim($link->getAttribute('href'));
            if (empty($href)) continue;

            $resolved = self::resolveUrl($href, $pageUrl);
            if (!empty($resolved)) {
                $links[] = [
                    'url' => $resolved,
                    'text' => '',
                    'type' => 'css',
                ];
            }
        }

        // Extract <script> src
        $scripts = $doc->getElementsByTagName('script');
        foreach ($scripts as $script) {
            $src = trim($script->getAttribute('src'));
            if (empty($src)) continue;

            $resolved = self::resolveUrl($src, $pageUrl);
            if (!empty($resolved)) {
                $links[] = [
                    'url' => $resolved,
                    'text' => '',
                    'type' => 'script',
                ];
            }
        }

        // Deduplicate by URL
        $seen = [];
        $unique = [];
        foreach ($links as $link) {
            $key = $link['url'] . '|' . $link['type'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $link;
            }
        }

        return $unique;
    }

    private static function resolveUrl(string $url, string $base): string {
        $url = trim($url);

        if (empty($url) || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') ||
            str_starts_with($url, 'tel:') || str_starts_with($url, 'javascript:') ||
            str_starts_with($url, 'data:')) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            return self::cleanUrl($url);
        }

        $parsed = parse_url($base);
        if (!$parsed || !isset($parsed['host'])) return '';

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        if (str_starts_with($url, '/')) {
            return self::cleanUrl($scheme . '://' . $host . $port . $url);
        }

        $basePath = $parsed['path'] ?? '/';
        $dir = rtrim(dirname($basePath), '/');
        return self::cleanUrl($scheme . '://' . $host . $port . $dir . '/' . $url);
    }

    private static function cleanUrl(string $url): string {
        $url = preg_replace('/#.*$/', '', $url);
        return $url;
    }

    private static function isYoutubeUrl(string $url): bool {
        return (bool) preg_match('#(youtube\.com|youtu\.be)#i', $url);
    }

    private static function parseSrcset(string $srcset): array {
        $urls = [];
        $parts = explode(',', $srcset);
        foreach ($parts as $part) {
            $tokens = preg_split('/\s+/', trim($part));
            if (!empty($tokens[0])) {
                $urls[] = $tokens[0];
            }
        }
        return $urls;
    }
}
