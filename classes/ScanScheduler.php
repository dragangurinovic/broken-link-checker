<?php

class ScanScheduler {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getDueScans(): array {
        $stmt = $this->db->prepare("SELECT ss.*, s.name as site_name, s.url as site_url
            FROM scan_schedules ss
            JOIN sites s ON ss.site_id = s.id
            WHERE ss.enabled = 1 AND s.enabled = 1
            AND (ss.next_run_at IS NULL OR ss.next_run_at <= datetime('now'))");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function calculateNextRun(array $schedule): string {
        $tz = new DateTimeZone($schedule['timezone'] ?? 'America/New_York');
        $now = new DateTime('now', $tz);

        $hour = (int) $schedule['hour'];
        $minute = (int) $schedule['minute'];

        switch ($schedule['frequency']) {
            case 'daily':
                $next = clone $now;
                $next->setTime($hour, $minute);
                if ($next <= $now) $next->modify('+1 day');
                break;

            case 'weekly':
                $dayOfWeek = (int) ($schedule['day_of_week'] ?? 1);
                $next = clone $now;
                $next->setTime($hour, $minute);
                $currentDow = (int) $next->format('w');
                $diff = $dayOfWeek - $currentDow;
                if ($diff < 0 || ($diff === 0 && $next <= $now)) $diff += 7;
                $next->modify("+{$diff} days");
                break;

            case 'biweekly':
                $dayOfWeek = (int) ($schedule['day_of_week'] ?? 1);
                $next = clone $now;
                $next->setTime($hour, $minute);
                $currentDow = (int) $next->format('w');
                $diff = $dayOfWeek - $currentDow;
                if ($diff < 0 || ($diff === 0 && $next <= $now)) $diff += 14;
                $next->modify("+{$diff} days");
                break;

            case 'monthly':
                $dayOfMonth = min((int) ($schedule['day_of_month'] ?? 1), 28);
                $next = clone $now;
                $next->setDate((int) $next->format('Y'), (int) $next->format('m'), $dayOfMonth);
                $next->setTime($hour, $minute);
                if ($next <= $now) $next->modify('+1 month');
                break;

            default:
                $next = clone $now;
                $next->modify('+1 week');
                $next->setTime($hour, $minute);
        }

        $next->setTimezone(new DateTimeZone('UTC'));
        return $next->format('Y-m-d H:i:s');
    }

    public function updateNextRun(int $scheduleId, array $schedule): void {
        $nextRun = $this->calculateNextRun($schedule);
        $stmt = $this->db->prepare("UPDATE scan_schedules SET next_run_at = ?, last_run_at = datetime('now') WHERE id = ?");
        $stmt->execute([$nextRun, $scheduleId]);
    }

    public function isAnyScanRunning(): bool {
        $maxConcurrent = (int) Database::getSetting('max_concurrent_scans', '1');
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM scans WHERE status = 'running'");
        $stmt->execute();
        return $stmt->fetchColumn() >= $maxConcurrent;
    }
}
