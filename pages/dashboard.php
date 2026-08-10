<?php
/**
 * Dashboard - Overview of all sites
 */

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

$db = Database::getInstance();

// Get site health overview
$sites = $db->query("SELECT s.*,
    (SELECT health_score FROM scans WHERE site_id = s.id AND status = 'completed' ORDER BY completed_at DESC LIMIT 1) as health_score,
    (SELECT links_broken FROM scans WHERE site_id = s.id AND status = 'completed' ORDER BY completed_at DESC LIMIT 1) as last_broken,
    (SELECT links_checked FROM scans WHERE site_id = s.id AND status = 'completed' ORDER BY completed_at DESC LIMIT 1) as last_checked,
    (SELECT pages_crawled FROM scans WHERE site_id = s.id AND status = 'completed' ORDER BY completed_at DESC LIMIT 1) as last_pages,
    (SELECT completed_at FROM scans WHERE site_id = s.id AND status = 'completed' ORDER BY completed_at DESC LIMIT 1) as last_scan_date,
    (SELECT status FROM scans WHERE site_id = s.id ORDER BY created_at DESC LIMIT 1) as current_scan_status,
    (SELECT id FROM scans WHERE site_id = s.id AND status = 'running' LIMIT 1) as running_scan_id,
    ss.frequency, ss.next_run_at, ss.enabled as schedule_enabled
    FROM sites s
    LEFT JOIN scan_schedules ss ON s.id = ss.site_id
    WHERE s.enabled = 1
    ORDER BY s.name")->fetchAll();

// Summary stats
$totalSites = count($sites);
$totalBroken = 0;
$avgHealth = 0;
$healthCount = 0;
$lastScanDate = null;

foreach ($sites as $site) {
    $totalBroken += $site['last_broken'] ?? 0;
    if ($site['health_score'] !== null) {
        $avgHealth += $site['health_score'];
        $healthCount++;
    }
    if ($site['last_scan_date'] && (!$lastScanDate || $site['last_scan_date'] > $lastScanDate)) {
        $lastScanDate = $site['last_scan_date'];
    }
}
$avgHealth = $healthCount > 0 ? round($avgHealth / $healthCount, 1) : null;

// Recent broken links across all sites
$recentBroken = $db->query("SELECT l.*, s.name as site_name
    FROM links l
    JOIN sites s ON l.site_id = s.id
    WHERE l.scan_id IN (
        SELECT MAX(sc.id) FROM scans sc WHERE sc.status = 'completed' GROUP BY sc.site_id
    )
    AND l.status_category IN ('broken', 'warning', 'blocked')
    ORDER BY l.status_category ASC, l.http_status ASC
    LIMIT 20")->fetchAll();

$extraJs = ['assets/js/dashboard.js'];
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h1 style="font-size: 22px; font-weight: 700; color: var(--gray-900);">Dashboard</h1>
</div>

<!-- Summary Stats -->
<div class="stats-grid">
    <div class="stat-card primary">
        <div class="stat-label">Sites Monitored</div>
        <div class="stat-value"><?= $totalSites ?></div>
    </div>
    <div class="stat-card <?= $totalBroken > 0 ? 'danger' : 'success' ?>">
        <div class="stat-label">Broken Links</div>
        <div class="stat-value"><?= $totalBroken ?></div>
        <div class="stat-detail">Across all sites</div>
    </div>
    <div class="stat-card <?= ($avgHealth !== null && $avgHealth >= 90) ? 'success' : 'warning' ?>">
        <div class="stat-label">Avg Health Score</div>
        <div class="stat-value"><?= $avgHealth !== null ? $avgHealth : 'N/A' ?></div>
        <div class="stat-detail">Out of 100</div>
    </div>
    <div class="stat-card info">
        <div class="stat-label">Last Scan</div>
        <div class="stat-value" style="font-size: 18px;"><?= $lastScanDate ? blc_time_ago($lastScanDate) : 'Never' ?></div>
    </div>
</div>

<!-- Site Cards -->
<h2 style="font-size: 16px; font-weight: 600; margin-bottom: 16px; color: var(--gray-800);">Site Health</h2>
<div class="sites-grid">
    <?php foreach ($sites as $site): ?>
        <a href="index.php?page=site-detail&site_id=<?= $site['id'] ?>" class="site-card" data-site-id="<?= $site['id'] ?>">
            <div class="site-card-header">
                <div>
                    <div class="site-card-name"><?= htmlspecialchars($site['name']) ?></div>
                    <div class="site-card-url"><?= htmlspecialchars(str_replace(['https://', 'http://'], '', $site['url'])) ?></div>
                </div>
                <?php if ($site['health_score'] !== null): ?>
                    <div class="health-score" style="background: <?= blc_health_color($site['health_score']) ?>">
                        <?= round($site['health_score']) ?>
                    </div>
                <?php else: ?>
                    <div class="health-score" style="background: var(--gray-400)">--</div>
                <?php endif; ?>
            </div>

            <div class="site-card-stats">
                <div class="site-card-stat">
                    <div class="site-card-stat-value" style="color: var(--danger)"><?= $site['last_broken'] ?? 0 ?></div>
                    <div class="site-card-stat-label">Broken</div>
                </div>
                <div class="site-card-stat">
                    <div class="site-card-stat-value"><?= $site['last_checked'] ?? 0 ?></div>
                    <div class="site-card-stat-label">Links</div>
                </div>
                <div class="site-card-stat">
                    <div class="site-card-stat-value"><?= $site['last_pages'] ?? 0 ?></div>
                    <div class="site-card-stat-label">Pages</div>
                </div>
            </div>

            <div class="site-card-footer">
                <div class="site-card-meta">
                    <?php if ($site['last_scan_date']): ?>
                        Scanned <?= blc_time_ago($site['last_scan_date']) ?>
                    <?php else: ?>
                        Never scanned
                    <?php endif; ?>
                </div>
                <?php if ($site['current_scan_status'] === 'running'): ?>
                    <span class="badge badge-info"><span class="spinner" style="width:10px;height:10px;border-width:1.5px;margin-right:4px;"></span> Scanning</span>
                <?php else: ?>
                    <button class="btn btn-primary btn-sm scan-btn" onclick="event.preventDefault(); BLC.startScan(<?= $site['id'] ?>)">Scan Now</button>
                <?php endif; ?>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<!-- Recent Broken Links -->
<?php if (!empty($recentBroken)): ?>
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3>Recent Broken Links</h3>
        <span class="badge badge-danger"><?= count($recentBroken) ?></span>
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Site</th>
                    <th>Source Page</th>
                    <th>Broken Link</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Category</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentBroken as $link): ?>
                    <tr>
                        <td><?= htmlspecialchars($link['site_name']) ?></td>
                        <td class="url-cell">
                            <a href="<?= htmlspecialchars($link['source_url']) ?>" target="_blank" title="<?= htmlspecialchars($link['source_url']) ?>">
                                <?= htmlspecialchars(blc_truncate($link['source_url'], 40)) ?>
                            </a>
                        </td>
                        <td class="url-cell">
                            <a href="<?= htmlspecialchars($link['target_url']) ?>" target="_blank" title="<?= htmlspecialchars($link['target_url']) ?>">
                                <?= htmlspecialchars(blc_truncate($link['target_url'], 40)) ?>
                            </a>
                        </td>
                        <td><?= $link['http_status'] ?? 'ERR' ?></td>
                        <td><?= htmlspecialchars($link['link_type']) ?></td>
                        <td><?= blc_status_badge($link['status_category']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
