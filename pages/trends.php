<?php
/**
 * Trends - Historical health data
 */

$pageTitle = 'Trends';
$currentPage = 'trends';

$db = Database::getInstance();

$sites = $db->query('SELECT id, name FROM sites WHERE enabled = 1 ORDER BY name')->fetchAll();

$trendData = [];
foreach ($sites as $site) {
    $stmt = $db->prepare('SELECT snapshot_date, health_score, broken_links, total_links, avg_response_ms, pages_crawled FROM trend_snapshots WHERE site_id = ? ORDER BY snapshot_date DESC LIMIT 30');
    $stmt->execute([$site['id']]);
    $rows = $stmt->fetchAll();
    $trendData[$site['id']] = [
        'name' => $site['name'],
        'data' => array_reverse($rows),
    ];
}

$chartColors = ['#3b82f6', '#ef4444', '#22c55e', '#f59e0b', '#6366f1', '#ec4899', '#14b8a6'];
?>

<div class="page-header" style="margin-bottom: 24px;">
    <h1 style="font-size: 22px; font-weight: 700;">Trends</h1>
</div>

<?php if (empty(array_filter($trendData, fn($t) => !empty($t['data'])))): ?>
<div class="card">
    <div class="card-body">
        <div class="empty-state">
            <h3>No trend data yet</h3>
            <p>Trend data is recorded after each scan completes. Run scans to see historical data.</p>
        </div>
    </div>
</div>
<?php else: ?>

<div class="card" style="margin-bottom: 24px;">
    <div class="card-header"><h3>Health Score Over Time</h3></div>
    <div class="card-body">
        <canvas id="healthChart" height="300" style="max-height: 300px;"></canvas>
    </div>
</div>

<div class="card" style="margin-bottom: 24px;">
    <div class="card-header"><h3>Broken Links Over Time</h3></div>
    <div class="card-body">
        <canvas id="brokenChart" height="300" style="max-height: 300px;"></canvas>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const trendData = <?= json_encode($trendData) ?>;
const colors = <?= json_encode($chartColors) ?>;

function buildChart(canvasId, labelKey, valueKey, yLabel) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    const datasets = [];
    let i = 0;

    Object.entries(trendData).forEach(([siteId, info]) => {
        if (info.data.length === 0) return;
        datasets.push({
            label: info.name,
            data: info.data.map(d => ({ x: d.snapshot_date, y: d[valueKey] })),
            borderColor: colors[i % colors.length],
            backgroundColor: colors[i % colors.length] + '22',
            tension: 0.3,
            fill: false,
            pointRadius: 3,
        });
        i++;
    });

    new Chart(ctx, {
        type: 'line',
        data: { datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { type: 'category', title: { display: true, text: 'Date' } },
                y: { title: { display: true, text: yLabel }, beginAtZero: valueKey !== 'health_score' },
            },
            plugins: {
                legend: { position: 'bottom' },
            },
        },
    });
}

buildChart('healthChart', 'snapshot_date', 'health_score', 'Health Score');
buildChart('brokenChart', 'snapshot_date', 'broken_links', 'Broken Links');
</script>
<?php endif; ?>
