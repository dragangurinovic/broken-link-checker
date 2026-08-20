<?php
/**
 * Reads WordPress posts directly from MySQL — no HTTP requests needed.
 * Uses mysqli (available on all cPanel servers) instead of PDO MySQL.
 */

class WpDatabaseReader {
    private mysqli $wpDb;
    private string $prefix;
    private string $siteUrl;

    public function __construct(array $siteConfig) {
        $host = $siteConfig['wp_db_host'] ?: 'localhost';
        $dbName = $siteConfig['wp_db_name'];
        $user = $siteConfig['wp_db_user'];
        $pass = $siteConfig['wp_db_pass'] ?? '';
        $this->prefix = $siteConfig['wp_table_prefix'] ?: 'wp_';
        $this->siteUrl = rtrim($siteConfig['url'] ?? '', '/');

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->wpDb = new mysqli($host, $user, $pass, $dbName);
        $this->wpDb->set_charset('utf8mb4');
    }

    public function __destruct() {
        if (isset($this->wpDb) && $this->wpDb instanceof mysqli) {
            $this->wpDb->close();
        }
    }

    public function getPublishedContent(int $limit = 0, int $offset = 0): array {
        $posts = $this->esc($this->prefix . 'posts');

        $sql = "SELECT ID, post_title, post_content, post_type, post_name, post_parent, post_date
                FROM {$posts}
                WHERE post_status = 'publish'
                  AND post_type IN ('post', 'page')
                  AND post_content != ''
                ORDER BY ID ASC";

        if ($limit > 0) {
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $result = $this->wpDb->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function countPublished(): int {
        $posts = $this->esc($this->prefix . 'posts');
        $result = $this->wpDb->query(
            "SELECT COUNT(*) as cnt FROM {$posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post', 'page')
               AND post_content != ''"
        );
        $row = $result->fetch_assoc();
        return (int) $row['cnt'];
    }

    public function getPermalink(array $post): string {
        static $structure = null;

        if ($structure === null) {
            $structure = $this->getOption('permalink_structure') ?: '/?p=%post_id%';
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
        $parentId = (int) $post['post_parent'];

        while ($parentId > 0) {
            $stmt = $this->wpDb->prepare(
                "SELECT post_name, post_parent FROM {$this->esc($this->prefix . 'posts')} WHERE ID = ?"
            );
            $stmt->bind_param('i', $parentId);
            $stmt->execute();
            $result = $stmt->get_result();
            $parent = $result->fetch_assoc();
            $stmt->close();
            if (!$parent) break;
            array_unshift($slugs, $parent['post_name']);
            $parentId = (int) $parent['post_parent'];
        }

        return $this->siteUrl . '/' . implode('/', $slugs) . '/';
    }

    private function getOption(string $name): ?string {
        static $cache = [];
        if (isset($cache[$name])) return $cache[$name];

        $table = $this->esc($this->prefix . 'options');
        $stmt = $this->wpDb->prepare(
            "SELECT option_value FROM {$table} WHERE option_name = ? LIMIT 1"
        );
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $cache[$name] = $row ? ($row['option_value'] ?: null) : null;
        return $cache[$name];
    }

    private function getPostCategory(int $postId): string {
        $terms = $this->esc($this->prefix . 'terms');
        $tt = $this->esc($this->prefix . 'term_taxonomy');
        $tr = $this->esc($this->prefix . 'term_relationships');
        $stmt = $this->wpDb->prepare(
            "SELECT t.slug FROM {$terms} t
             JOIN {$tt} tt ON t.term_id = tt.term_id
             JOIN {$tr} tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = ? AND tt.taxonomy = 'category'
             ORDER BY t.term_id ASC LIMIT 1"
        );
        $stmt->bind_param('i', $postId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ? $row['slug'] : 'uncategorized';
    }

    private function getPostAuthorSlug(int $postId): string {
        $users = $this->esc($this->prefix . 'users');
        $posts = $this->esc($this->prefix . 'posts');
        $stmt = $this->wpDb->prepare(
            "SELECT u.user_nicename FROM {$users} u
             JOIN {$posts} p ON p.post_author = u.ID
             WHERE p.ID = ?"
        );
        $stmt->bind_param('i', $postId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ? $row['user_nicename'] : 'admin';
    }

    public function wrapContent(string $content): string {
        $content = $this->expandShortcodeUrls($content);
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';
    }

    private function expandShortcodeUrls(string $content): string {
        $content = preg_replace_callback(
            '#\[embed\](https?://[^\[]+)\[/embed\]#i',
            fn($m) => '<a href="' . htmlspecialchars($m[1]) . '">' . htmlspecialchars($m[1]) . '</a>',
            $content
        );

        $content = preg_replace_callback(
            '#\[(video|audio)\s[^\]]*src=["\']([^"\']+)["\'][^\]]*\]#i',
            fn($m) => '<a href="' . htmlspecialchars($m[2]) . '">' . htmlspecialchars($m[2]) . '</a>',
            $content
        );

        $content = preg_replace_callback(
            '#^(https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/|vimeo\.com/)\S+)$#mi',
            fn($m) => '<a href="' . htmlspecialchars($m[1]) . '">' . htmlspecialchars($m[1]) . '</a>',
            $content
        );

        return $content;
    }

    /**
     * Sanitize a table name (only allow alphanumeric and underscore).
     */
    private function esc(string $identifier): string {
        return '`' . preg_replace('/[^a-zA-Z0-9_]/', '', $identifier) . '`';
    }

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
