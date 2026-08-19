<?php
/**
 * Reads WordPress posts directly from MySQL — no HTTP requests needed.
 * Used for WP sites on the same server to avoid loading Apache.
 */

class WpDatabaseReader {
    private PDO $wpDb;
    private string $prefix;
    private string $siteUrl;

    public function __construct(array $siteConfig) {
        $host = $siteConfig['wp_db_host'] ?: 'localhost';
        $dbName = $siteConfig['wp_db_name'];
        $user = $siteConfig['wp_db_user'];
        $pass = $siteConfig['wp_db_pass'];
        $this->prefix = $siteConfig['wp_table_prefix'] ?: 'wp_';
        $this->siteUrl = rtrim($siteConfig['url'], '/');

        $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
        $this->wpDb = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_CONNECT_TIMEOUT => 5,
        ]);
    }

    /**
     * Get all published posts/pages with their URLs and HTML content.
     * Returns an iterator to avoid loading everything into memory at once.
     */
    public function getPublishedContent(int $limit = 0, int $offset = 0): array {
        $posts = $this->prefix . 'posts';

        $sql = "SELECT ID, post_title, post_content, post_type, post_name, post_parent, post_date
                FROM {$posts}
                WHERE post_status = 'publish'
                  AND post_type IN ('post', 'page')
                  AND post_content != ''
                ORDER BY ID ASC";

        if ($limit > 0) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $stmt = $this->wpDb->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Count total published posts/pages.
     */
    public function countPublished(): int {
        $posts = $this->prefix . 'posts';
        $stmt = $this->wpDb->query(
            "SELECT COUNT(*) FROM {$posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post', 'page')
               AND post_content != ''"
        );
        return (int) $stmt->fetchColumn();
    }

    /**
     * Build the permalink for a post. Queries the options table for the
     * permalink structure and reconstructs the URL.
     */
    public function getPermalink(array $post): string {
        static $structure = null;
        static $categoryBase = null;

        if ($structure === null) {
            $structure = $this->getOption('permalink_structure') ?: '/?p=%post_id%';
            $categoryBase = $this->getOption('category_base') ?: 'category';
        }

        if ($post['post_type'] === 'page') {
            return $this->getPagePermalink($post);
        }

        if (empty($structure) || $structure === '/?p=%post_id%' || str_contains($structure, '?')) {
            return $this->siteUrl . '/?p=' . $post['ID'];
        }

        $permalink = $structure;

        $date = new DateTime($post['post_date']);
        $replacements = [
            '%year%' => $date->format('Y'),
            '%monthnum%' => $date->format('m'),
            '%day%' => $date->format('d'),
            '%hour%' => $date->format('H'),
            '%minute%' => $date->format('i'),
            '%second%' => $date->format('s'),
            '%post_id%' => $post['ID'],
            '%postname%' => $post['post_name'],
        ];

        if (str_contains($permalink, '%category%')) {
            $replacements['%category%'] = $this->getPostCategory($post['ID']);
        }
        if (str_contains($permalink, '%author%')) {
            $replacements['%author%'] = $this->getPostAuthorSlug($post['ID']);
        }

        $permalink = str_replace(array_keys($replacements), array_values($replacements), $permalink);

        return $this->siteUrl . $permalink;
    }

    private function getPagePermalink(array $post): string {
        $slugs = [$post['post_name']];
        $parentId = $post['post_parent'];

        while ($parentId > 0) {
            $stmt = $this->wpDb->prepare(
                "SELECT post_name, post_parent FROM {$this->prefix}posts WHERE ID = ?"
            );
            $stmt->execute([$parentId]);
            $parent = $stmt->fetch();
            if (!$parent) break;
            array_unshift($slugs, $parent['post_name']);
            $parentId = $parent['post_parent'];
        }

        return $this->siteUrl . '/' . implode('/', $slugs) . '/';
    }

    private function getOption(string $name): ?string {
        static $cache = [];
        if (isset($cache[$name])) return $cache[$name];

        $stmt = $this->wpDb->prepare(
            "SELECT option_value FROM {$this->prefix}options WHERE option_name = ? LIMIT 1"
        );
        $stmt->execute([$name]);
        $val = $stmt->fetchColumn();
        $cache[$name] = $val ?: null;
        return $cache[$name];
    }

    private function getPostCategory(int $postId): string {
        $stmt = $this->wpDb->prepare(
            "SELECT t.slug FROM {$this->prefix}terms t
             JOIN {$this->prefix}term_taxonomy tt ON t.term_id = tt.term_id
             JOIN {$this->prefix}term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = ? AND tt.taxonomy = 'category'
             ORDER BY t.term_id ASC LIMIT 1"
        );
        $stmt->execute([$postId]);
        return $stmt->fetchColumn() ?: 'uncategorized';
    }

    private function getPostAuthorSlug(int $postId): string {
        $stmt = $this->wpDb->prepare(
            "SELECT u.user_nicename FROM {$this->prefix}users u
             JOIN {$this->prefix}posts p ON p.post_author = u.ID
             WHERE p.ID = ?"
        );
        $stmt->execute([$postId]);
        return $stmt->fetchColumn() ?: 'admin';
    }

    /**
     * Wrap post_content in a basic HTML doc so LinkExtractor can parse it.
     * WordPress stores content as partial HTML (no head/body tags).
     */
    public function wrapContent(string $content): string {
        $content = $this->expandShortcodeUrls($content);
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';
    }

    /**
     * Expand common shortcodes that contain URLs so LinkExtractor finds them.
     * We don't execute shortcodes (no WP runtime), but we can extract URLs.
     */
    private function expandShortcodeUrls(string $content): string {
        // [embed]https://youtube.com/...[/embed]
        $content = preg_replace_callback(
            '#\[embed\](https?://[^\[]+)\[/embed\]#i',
            fn($m) => '<a href="' . htmlspecialchars($m[1]) . '">' . htmlspecialchars($m[1]) . '</a>',
            $content
        );

        // [video src="..."] / [audio src="..."]
        $content = preg_replace_callback(
            '#\[(video|audio)\s[^\]]*src=["\']([^"\']+)["\'][^\]]*\]#i',
            fn($m) => '<a href="' . htmlspecialchars($m[2]) . '">' . htmlspecialchars($m[2]) . '</a>',
            $content
        );

        // YouTube/Vimeo URLs on their own line (WP auto-embeds these)
        $content = preg_replace_callback(
            '#^(https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|vimeo\.com/)\S+)$#mi',
            fn($m) => '<a href="' . htmlspecialchars($m[1]) . '">' . htmlspecialchars($m[1]) . '</a>',
            $content
        );

        return $content;
    }

    /**
     * Test the database connection. Returns true or throws.
     */
    public static function testConnection(array $siteConfig): array {
        try {
            $reader = new self($siteConfig);
            $count = $reader->countPublished();
            return ['success' => true, 'post_count' => $count];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
