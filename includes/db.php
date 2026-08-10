<?php
/**
 * Database connection and migration runner
 */

require_once __DIR__ . '/../config/config.php';

class Database {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dbDir = dirname(BLC_DB_PATH);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            self::$instance = new PDO('sqlite:' . BLC_DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            self::$instance->exec('PRAGMA journal_mode = WAL');
            self::$instance->exec('PRAGMA busy_timeout = 5000');
            self::$instance->exec('PRAGMA foreign_keys = ON');
            self::$instance->exec('PRAGMA synchronous = NORMAL');
        }

        return self::$instance;
    }

    public static function runMigrations(): void {
        $db = self::getInstance();

        $db->exec('CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename TEXT NOT NULL UNIQUE,
            applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        $applied = $db->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        $migrationDir = BLC_ROOT . '/migrations';
        $files = glob($migrationDir . '/*.sql');
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            if (!in_array($filename, $applied)) {
                $sql = file_get_contents($file);
                $db->exec($sql);
                $stmt = $db->prepare('INSERT INTO migrations (filename) VALUES (?)');
                $stmt->execute([$filename]);
            }
        }
    }

    public static function getSetting(string $key, string $default = ''): string {
        $db = self::getInstance();
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    public static function setSetting(string $key, string $value): void {
        $db = self::getInstance();
        $stmt = $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime(\'now\'))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime(\'now\')');
        $stmt->execute([$key, $value]);
    }
}
