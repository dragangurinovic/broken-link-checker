<?php
/**
 * Site Detail - View scan results for a specific site
 */

$siteId = (int) ($_GET['site_id'] ?? 0);
if ($siteId <= 0) {
    header('Location: index.php');
    exit;
}

$db = Database::getInstance();
$site = $db->prepare('SELECT * FROM sites WHERE id = ?');
$site->execute([$siteId]);
$site = $site->fetch();

if (!$site) {
    header('Location: index.php');
    exit;
}

$pageTitle = $site['name'];
$currentPage = 'site-detail';

// Get latest completed scan
$latestScan = $db->prepare("SELECT * FROM scans WHERE site_id = ? AND status = 'completed' ORDER BY completed_at DESC LIMIT 1");
$latestScan->execute([$siteId]);
$latestScan = $latestScan->fetch();

// Check for running scan
$runningScan = $db->prepare("SELECT * FROM scans WHERE site_id = ? AND status = 'running' LIMIT 1");
$runningScan->execute([$siteId]);
$runningScan = $runningScan->fetch();

// Get schedule
$schedule = $db->prepare('SELECT * FROM scan_schedules WHERE site_id = ?');
$schedule->execute([$siteId]);
$schedule = $schedule->fetch();

// Filter
$statusFilter = $_GET['status'] ?? 'broken';
$linkType = $_GET['link_type'] ?? '';
$search = $_GET['search'] ?? '';
$pg = max(1, (int) ($_GET['pg'] ?? 1));
$perPage = 50;
$offset = ($pg - 1) * $perPage;

$scanId = $latestScan['id'] ?? 0;

// Count by status
$statusCounts = [];
if ($scanId) {
    $stmt = $db->prepare("SELECT status_category, COUNT(*) as cnt FROM links WHERE scan_id = ? GROUP BY status_category");
    $stmt->execute([$scanId]);
    $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Build query
$where = ['l.scan_id = ?'];
$params = [$scanId];

if ($statusFilter && $statusFilter !== 'all') {
    if ($statusFilter === 'broken') {
        $where[] = "l.status_category IN ('broken', 'warning', 'blocked')";
    } else {
        $where[] = 'l.status_category = ?';
        $params[] = $statusFilter;
    }
}

if ($linkType) {
    $where[] = 'l.link_type = ?';
    $params[] = $linkType;
}

if ($search) {
    $where[] = '(l.target_url LIKE ? OR l.source_url LIKE ? OR l.link_text LIKE ?)';
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereStr = implode(' AND ', $where);

// Total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM links l WHERE {$whereStr}");
$countStmt->execute($params);
$totalLinks = $countStmt->fetchColumn();
$totalPages = ceil($totalLinks / $perPage);
$currentPg = $pg;

// Get links
$links = [];
if ($scanId) {
    $stmt = $db->prepare("SELECT l.* FROM links l WHERE {$whereStr} ORDER BY
        CASE l.status_category WHEN 'broken' THEN 1 WHEN 'warning' THEN 2 WHEN 'blocked' THEN 3 WHEN 'timeout' THEN 4 ELSE 5 END,
        l.http_status ASC
        LIMIT ? OFFSET ?");
    $params[] = $perPage;
    $params[] = $offset;
    $stmt->execute($params);
    $links = $stmt->fetchAll();
}

// Scan history
$scanHistory = $db->prepare("SELECT id, status, trigger_type, pages_crawled, links_checked, links_broken, health_score, duration_secs, started_at, completed_at FROM scans WHERE site_id = ? ORDER BY created_at DESC LIMIT 10");
$scanHistory->execute([$siteId]);
$scanHistory = $scanHistory->fetchAll();
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; color: var(--gray-900);"><?= htmlspecialchars($site['name']) ?></h1>
        <div style="font-size: 13px; color: var(--gray-500); margin-top: 4px;">
            <a href="<?= htmlspecialchars($site['url']) ?>" target="_blank"><?= htmlspecialchars($site['url']) ?></a>
            <?php if ($schedule): ?>
                <span class="schedule-badge" style="margin-left: 8px;">
                    <?= ucfirst($schedule['frequency']) ?> @ <?= sprintf('%02d:%02d', $schedule['hour'], $schedule['minute']) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="btn-group">
        <?php if ($runningScan): ?>
            <button class="btn btn-danger" onclick="BLC.stopScan(<?= $runningScan['id'] ?>)">
                <span class="spinner"></span> Cancel Scan
            </button>
        <?php else: ?>
            <button class="btn btn-primary" onclick="BLC.startScan(<?= $siteId ?>)">Scan Now</button>
        <?php endif; ?>
        <?php if ($scanId): ?>
            <a href="api/export.php?site_id=<?= $siteId ?>&scan_id=<?= $scanId ?>" class="btn btn-outline">Export CSV</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($latestScan): ?>
<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card <?= ($latestScan['health_score'] >= 90) ? 'success' : (($latestScan['health_score'] >= 70) ? 'warning' : 'danger') ?>">
        <div class="stat-label">Health Score</div>
        <div class="stat-value"><?= round($latestScan['health_score'], 1) ?></div>
    </div>
    <div class="stat-card danger">
        <div class="stat-label">Broken</div>
        <div class="stat-value"><?= $latestScan['links_broken'] ?></div>
    </div>
    <div class="stat-card primary">
        <div class="stat-label">Links Checked</div>
        <div class="stat-value"><?= $latestScan['links_checked'] ?></div>
    </div>
    <div class="stat-card info">
        <div class="stat-label">Pages Crawled</div>
        <div class="stat-value"><?= $latestScan['pages_crawled'] ?></div>
    </div>
</div>

<!-- Filter tabs -->
<div class="card">
    <div class="card-header" style="flex-direction: column; align-items: stretch;">
        <div class="tab-bar" style="margin-bottom: 0; border-bottom: none;">
            <a href="?page=site-detail&site_id=<?= $siteId ?>&status=broken" class="tab-item <?= $statusFilter === 'broken' ? 'active' : '' ?>">
                Problems <span class="tab-count"><?= ($statusCounts['broken'] ?? 0) + ($statusCounts['warning'] ?? 0) + ($statusCounts['blocked'] ?? 0) ?></span>
            </a>
            <a href="?page=site-detail&site_id=<?= $siteId ?>&status=ok" class="tab-item <?= $statusFilter === 'ok' ? 'active' : '' ?>">
                OK <span class="tab-count"><?= $statusCounts['ok'] ?? 0 ?></span>
            </a>
            <a href="?page=site-detail&site_id=<?= $siteId ?>&status=ignored" class="tab-item <?= $statusFilter === 'ignored' ? 'active' : '' ?>">
                Ignored <span class="tab-count"><?= $statusCounts['ignored'] ?? 0 ?></span>
            </a>
            <a href="?page=site-detail&site_id=<?= $siteId ?>&status=all" class="tab-item <?= $statusFilter === 'all' ? 'active' : '' ?>">
                All <span class="tab-count"><?= array_sum($statusCounts) ?></span>
            </a>
        </div>
    </div>

    <!-- Toolbar -->
    <div style="padding: 12px 20px; border-bottom: 1px solid var(--gray-200);">
        <div class="toolbar" style="margin-bottom: 0;">
            <div class="toolbar-left">
                <form method="GET" style="display:flex;gap:8px;align-items:center;">
                    <input type="hidden" name="page" value="site-detail">
                    <input type="hidden" name="site_id" value="<?= $siteId ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                    <input type="text" name="search" class="search-input" placeholder="Search URLs..." value="<?= htmlspecialchars($search) ?>">
                    <select name="link_type" class="form-control" style="width:auto;" onchange="this.form.submit()">
                        <option value="">All Types</option>
                        <option value="anchor" <?= $linkType === 'anchor' ? 'selected' : '' ?>>Links</option>
                        <option value="image" <?= $linkType === 'image' ? 'selected' : '' ?>>Images</option>
                        <option value="youtube" <?= $linkType === 'youtube' ? 'selected' : '' ?>>YouTube</option>
                        <option value="css" <?= $linkType === 'css' ? 'selected' : '' ?>>CSS</option>
                        <option value="script" <?= $linkType === 'script' ? 'selected' : '' ?>>Scripts</option>
                    </select>
                </form>
            </div>
            <div class="toolbar-right">
                <span style="font-size: 12px; color: var(--gray-500);"><?= $totalLinks ?> results</span>
            </div>
        </div>
    </div>

    <!-- Links table -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all" class="row-checkbox"></th>
                    <th>Source Page</th>
                    <th>Broken Link</th>
                    <th>Anchor</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Response</th>
                    <th>Category</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($links)): ?>
                    <tr><td colspan="9" style="text-align:center; padding:40px; color: var(--gray-400);">
                        <?php if ($statusFilter === 'broken'): ?>
                            No broken links found - great!
                        <?php else: ?>
                            No links match this filter
                        <?php endif; ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($links as $link): ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" value="<?= $link['id'] ?>"></td>
                            <td class="url-cell">
                                <a href="<?= htmlspecialchars($link['source_url']) ?>" target="_blank" title="<?= htmlspecialchars($link['source_url']) ?>">
                                    <?= htmlspecialchars(blc_truncate($link['source_url'], 35)) ?>
                                </a>
                            </td>
                            <td class="url-cell">
                                <a href="<?= htmlspecialchars($link['target_url']) ?>" target="_blank" title="<?= htmlspecialchars($link['target_url']) ?>">
                                    <?= htmlspecialchars(blc_truncate($link['target_url'], 35)) ?>
                                </a>
                            </td>
                            <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($link['link_text'] ?? '') ?>">
                                <?= htmlspecialchars(blc_truncate($link['link_text'] ?? '', 20)) ?>
                            </td>
                            <td><span class="badge badge-light"><?= htmlspecialchars($link['link_type']) ?></span></td>
                            <td>
                                <strong><?= $link['http_status'] ?? 'ERR' ?></strong>
                                <?php if ($link['error_message']): ?>
                                    <div style="font-size:11px; color: var(--gray-400);" title="<?= htmlspecialchars($link['error_message']) ?>">
                                        <?= htmlspecialchars(blc_truncate($link['error_message'], 25)) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($link['response_time_ms']): ?>
                                    <?= $link['response_time_ms'] ?>ms
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= blc_status_badge($link['status_category']) ?>
                                <?php if ($link['block_type']): ?>
                                    <div style="font-size:10px; color: var(--gray-500);"><?= htmlspecialchars($link['block_type']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="dropdown">
                                    <button class="btn btn-outline btn-sm btn-icon dropdown-toggle" title="Actions">&#8943;</button>
                                    <div class="dropdown-menu">
                                        <button class="dropdown-item" onclick="BLC.recheckLink(<?= $link['id'] ?>).then(() => location.reload())">Recheck</button>
                                        <button class="dropdown-item" onclick="BLC.showIgnoreDialog('<?= htmlspecialchars(addslashes($link['target_url'])) ?>', <?= $siteId ?>)">Ignore</button>
                                        <a class="dropdown-item" href="<?= htmlspecialchars($link['target_url']) ?>" target="_blank">Open Link</a>
                                        <a class="dropdown-item" href="<?= htmlspecialchars($link['source_url']) ?>" target="_blank">Open Source Page</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php
    $paginationUrl = "?page=site-detail&site_id={$siteId}&status={$statusFilter}&link_type={$linkType}&search=" . urlencode($search);
    include __DIR__ . '/../templates/partials/pagination.php';
    ?>
</div>

<!-- Bulk action bar -->
<div class="bulk-bar" id="bulk-bar">
    <span id="selected-count">0 selected</span>
    <div class="btn-group">
        <button class="btn btn-sm btn-outline" onclick="BLC.bulkIgnore()">Ignore Selected</button>
        <button class="btn btn-sm btn-outline" onclick="BLC.bulkRecheck()">Recheck Selected</button>
    </div>
</div>

<?php else: ?>
<!-- No scan yet -->
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <h3>No scan results yet</h3>
            <p>Start your first scan to check all links on <?= htmlspecialchars($site['name']) ?>.</p>
            <button class="btn btn-primary" style="margin-top: 16px;" onclick="BLC.startScan(<?= $siteId ?>)">Start First Scan</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Scan History -->
<?php if (!empty($scanHistory)): ?>
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3>Scan History</h3>
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Scan ID</th>
                    <th>Status</th>
                    <th>Trigger</th>
                    <th>Pages</th>
                    <th>Links</th>
                    <th>Broken</th>
                    <th>Health</th>
                    <th>Duration</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scanHistory as $scan): ?>
                    <tr>
                        <td>#<?= $scan['id'] ?></td>
                        <td><?= blc_status_badge($scan['status']) ?></td>
                        <td><?= htmlspecialchars($scan['trigger_type']) ?></td>
                        <td><?= $scan['pages_crawled'] ?></td>
                        <td><?= $scan['links_checked'] ?></td>
                        <td style="color: <?= $scan['links_broken'] > 0 ? 'var(--danger)' : 'inherit' ?>; font-weight: <?= $scan['links_broken'] > 0 ? '600' : '400' ?>;">
                            <?= $scan['links_broken'] ?>
                        </td>
                        <td><?= $scan['health_score'] !== null ? round($scan['health_score'], 1) : '-' ?></td>
                        <td><?= $scan['duration_secs'] ? blc_format_duration($scan['duration_secs']) : '-' ?></td>
                        <td><?= $scan['completed_at'] ? blc_time_ago($scan['completed_at']) : ($scan['started_at'] ? 'Started ' . blc_time_ago($scan['started_at']) : '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
