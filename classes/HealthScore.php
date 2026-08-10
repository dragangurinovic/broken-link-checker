<?php

class HealthScore {
    public static function calculate(PDO $db, int $scanId): float {
        $stmt = $db->prepare("SELECT status_category, COUNT(*) as cnt FROM links WHERE scan_id = ? AND status_category != 'ignored' GROUP BY status_category");
        $stmt->execute([$scanId]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $total = array_sum($counts);
        if ($total === 0) return 100.0;

        $weights = [
            'broken' => 1.0,
            'warning' => 0.3,
            'blocked' => 0.1,
            'timeout' => 0.5,
        ];

        $penalty = 0;
        foreach ($weights as $category => $weight) {
            $penalty += ($counts[$category] ?? 0) * $weight;
        }

        $score = 100 * (1 - ($penalty / $total));
        return max(0, min(100, round($score, 1)));
    }

    public static function saveTrendSnapshot(PDO $db, int $scanId, int $siteId): void {
        $stmt = $db->prepare("SELECT status_category, COUNT(*) as cnt FROM links WHERE scan_id = ? GROUP BY status_category");
        $stmt->execute([$scanId]);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $scan = $db->prepare('SELECT pages_crawled, health_score FROM scans WHERE id = ?');
        $scan->execute([$scanId]);
        $scan = $scan->fetch();

        $avgResp = $db->prepare('SELECT AVG(response_time_ms) FROM links WHERE scan_id = ? AND response_time_ms IS NOT NULL');
        $avgResp->execute([$scanId]);
        $avgRespMs = $avgResp->fetchColumn();

        $stmt = $db->prepare('INSERT OR REPLACE INTO trend_snapshots (site_id, scan_id, snapshot_date, total_links, broken_links, warning_links, blocked_links, ok_links, health_score, avg_response_ms, pages_crawled)
            VALUES (?, ?, date(\'now\'), ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $siteId,
            $scanId,
            array_sum($counts),
            $counts['broken'] ?? 0,
            $counts['warning'] ?? 0,
            $counts['blocked'] ?? 0,
            $counts['ok'] ?? 0,
            $scan['health_score'] ?? 0,
            $avgRespMs ? (int) $avgRespMs : null,
            $scan['pages_crawled'] ?? 0,
        ]);
    }
}
