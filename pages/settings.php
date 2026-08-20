<?php
/**
 * Settings Page
 */

$pageTitle = 'Settings';
$currentPage = 'settings';

$db = Database::getInstance();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsToSave = [
        'concurrent_requests', 'request_timeout_secs', 'connect_timeout_secs',
        'max_retries', 'retry_delay_ms', 'default_rate_limit_ms', 'max_concurrent_scans',
        'notify_email_enabled', 'notify_email_to', 'notify_slack_enabled',
        'notify_slack_webhook', 'notify_slack_channel', 'notify_on_completion',
        'notify_only_if_broken', 'scanner_log_level',
    ];

    $checkboxes = ['notify_email_enabled', 'notify_slack_enabled', 'notify_on_completion', 'notify_only_if_broken'];

    foreach ($settingsToSave as $key) {
        if (in_array($key, $checkboxes)) {
            $value = isset($_POST[$key]) ? '1' : '0';
        } else {
            $value = $_POST[$key] ?? '';
        }
        Database::setSetting($key, $value);
    }

    // Save site schedules
    if (isset($_POST['schedule'])) {
        foreach ($_POST['schedule'] as $siteId => $sched) {
            $hour = 2;
            $minute = 0;
            if (!empty($sched['hour']) && str_contains($sched['hour'], ':')) {
                [$hour, $minute] = array_map('intval', explode(':', $sched['hour']));
            } elseif (isset($sched['hour'])) {
                $hour = (int) $sched['hour'];
            }

            $stmt = $db->prepare('UPDATE scan_schedules SET frequency = ?, day_of_week = ?, day_of_month = ?, hour = ?, minute = ?, enabled = ? WHERE site_id = ?');
            $stmt->execute([
                $sched['frequency'] ?? 'weekly',
                $sched['day_of_week'] ?? 1,
                $sched['day_of_month'] ?? 1,
                $hour,
                $minute,
                isset($sched['enabled']) ? 1 : 0,
                $siteId,
            ]);

            // Recalculate next run
            require_once BLC_ROOT . '/classes/ScanScheduler.php';
            $scheduler = new ScanScheduler($db);
            $schedRow = $db->prepare('SELECT * FROM scan_schedules WHERE site_id = ?');
            $schedRow->execute([$siteId]);
            $schedRow = $schedRow->fetch();
            if ($schedRow && $schedRow['enabled']) {
                $scheduler->updateNextRun($schedRow['id'], $schedRow);
            }
        }
    }

    // Save database config per site
    if (isset($_POST['wpdb'])) {
        foreach ($_POST['wpdb'] as $siteId => $wpdb) {
            $dbName = trim($wpdb['name'] ?? '');
            $dbUser = trim($wpdb['user'] ?? '');
            $scanMode = (!empty($dbName) && !empty($dbUser)) ? 'database' : 'http';
            $stmt = $db->prepare('UPDATE sites SET scan_mode = ?, wp_db_host = ?, wp_db_name = ?, wp_db_user = ?, wp_db_pass = ?, wp_table_prefix = ? WHERE id = ?');
            $stmt->execute([
                $scanMode,
                trim($wpdb['host'] ?? '') ?: 'localhost',
                $dbName,
                $dbUser,
                $wpdb['pass'] ?? '',
                trim($wpdb['prefix'] ?? '') ?: 'wp_',
                $siteId,
            ]);
        }
    }

    $_SESSION['flash_message'] = 'Settings saved successfully!';
    $_SESSION['flash_type'] = 'success';
    header('Location: index.php?page=settings');
    exit;
}

// Load current settings
$settings = [];
$rows = $db->query('SELECT key, value FROM settings')->fetchAll();
foreach ($rows as $row) {
    $settings[$row['key']] = $row['value'];
}

// Load sites with schedules
$sites = $db->query("SELECT s.*, ss.frequency, ss.day_of_week, ss.day_of_month, ss.hour, ss.minute, ss.enabled as schedule_enabled, ss.next_run_at
    FROM sites s LEFT JOIN scan_schedules ss ON s.id = ss.site_id WHERE s.enabled = 1 ORDER BY s.name")->fetchAll();
?>

<div class="page-header" style="margin-bottom: 24px;">
    <h1 style="font-size: 22px; font-weight: 700;">Settings</h1>
</div>

<form method="POST">
    <!-- Scanner Settings -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header"><h3>Scanner Settings</h3></div>
        <div class="card-body">
            <div class="settings-grid">
                <div class="form-group">
                    <label class="form-label">Concurrent Requests</label>
                    <input type="number" name="concurrent_requests" class="form-control" value="<?= htmlspecialchars($settings['concurrent_requests'] ?? '5') ?>" min="1" max="20">
                    <div class="form-hint">Max simultaneous HTTP connections (1-20)</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Request Timeout (seconds)</label>
                    <input type="number" name="request_timeout_secs" class="form-control" value="<?= htmlspecialchars($settings['request_timeout_secs'] ?? '30') ?>" min="5" max="120">
                </div>
                <div class="form-group">
                    <label class="form-label">Connect Timeout (seconds)</label>
                    <input type="number" name="connect_timeout_secs" class="form-control" value="<?= htmlspecialchars($settings['connect_timeout_secs'] ?? '10') ?>" min="3" max="30">
                </div>
                <div class="form-group">
                    <label class="form-label">Max Retries</label>
                    <input type="number" name="max_retries" class="form-control" value="<?= htmlspecialchars($settings['max_retries'] ?? '2') ?>" min="0" max="5">
                </div>
                <div class="form-group">
                    <label class="form-label">Retry Delay (ms)</label>
                    <input type="number" name="retry_delay_ms" class="form-control" value="<?= htmlspecialchars($settings['retry_delay_ms'] ?? '2000') ?>" min="500" max="10000">
                </div>
                <div class="form-group">
                    <label class="form-label">Rate Limit (ms between requests)</label>
                    <input type="number" name="default_rate_limit_ms" class="form-control" value="<?= htmlspecialchars($settings['default_rate_limit_ms'] ?? '500') ?>" min="100" max="5000">
                </div>
                <div class="form-group">
                    <label class="form-label">Max Concurrent Scans</label>
                    <input type="number" name="max_concurrent_scans" class="form-control" value="<?= htmlspecialchars($settings['max_concurrent_scans'] ?? '1') ?>" min="1" max="3">
                </div>
                <div class="form-group">
                    <label class="form-label">Log Level</label>
                    <select name="scanner_log_level" class="form-control">
                        <?php foreach (['debug', 'info', 'warn', 'error'] as $level): ?>
                            <option value="<?= $level ?>" <?= ($settings['scanner_log_level'] ?? 'info') === $level ? 'selected' : '' ?>><?= ucfirst($level) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Notifications -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header"><h3>Notifications</h3></div>
        <div class="card-body">
            <div class="settings-grid">
                <div>
                    <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Email</h4>
                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" name="notify_email_enabled" value="1" <?= ($settings['notify_email_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <span>Enable email notifications</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email Addresses</label>
                        <input type="text" name="notify_email_to" class="form-control" value="<?= htmlspecialchars($settings['notify_email_to'] ?? '') ?>" placeholder="email@example.com, email2@example.com">
                        <div class="form-hint">Comma-separated list</div>
                    </div>
                </div>
                <div>
                    <h4 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;">Slack</h4>
                    <div class="form-group">
                        <label class="form-check">
                            <input type="checkbox" name="notify_slack_enabled" value="1" <?= ($settings['notify_slack_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <span>Enable Slack notifications</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Webhook URL</label>
                        <input type="url" name="notify_slack_webhook" class="form-control" value="<?= htmlspecialchars($settings['notify_slack_webhook'] ?? '') ?>" placeholder="https://hooks.slack.com/services/...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Channel / Person (optional)</label>
                        <input type="text" name="notify_slack_channel" class="form-control" value="<?= htmlspecialchars($settings['notify_slack_channel'] ?? '') ?>" placeholder="#channel or @person">
                    </div>
                </div>
            </div>
            <div style="margin-top: 16px;">
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="notify_on_completion" value="1" <?= ($settings['notify_on_completion'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <span>Notify when scan completes</span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" name="notify_only_if_broken" value="1" <?= ($settings['notify_only_if_broken'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <span>Only notify if broken links found</span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Scan Schedules -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header"><h3>Scan Schedules</h3></div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Site</th>
                        <th>Enabled</th>
                        <th>Frequency</th>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Next Run</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $site): ?>
                        <tr>
                            <td><?= htmlspecialchars($site['name']) ?></td>
                            <td>
                                <input type="checkbox" name="schedule[<?= $site['id'] ?>][enabled]" value="1" <?= ($site['schedule_enabled'] ?? 0) ? 'checked' : '' ?>>
                            </td>
                            <td>
                                <select name="schedule[<?= $site['id'] ?>][frequency]" class="form-control" style="width: auto;">
                                    <?php foreach (['daily', 'weekly', 'biweekly', 'monthly'] as $freq): ?>
                                        <option value="<?= $freq ?>" <?= ($site['frequency'] ?? 'weekly') === $freq ? 'selected' : '' ?>><?= ucfirst($freq) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="schedule[<?= $site['id'] ?>][day_of_week]" class="form-control" style="width: auto;">
                                    <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $i => $day): ?>
                                        <option value="<?= $i ?>" <?= ($site['day_of_week'] ?? 1) == $i ? 'selected' : '' ?>><?= $day ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="time" name="schedule[<?= $site['id'] ?>][hour]" class="form-control" style="width:auto;" value="<?= sprintf('%02d:%02d', $site['hour'] ?? 2, $site['minute'] ?? 0) ?>">
                            </td>
                            <td style="font-size: 12px; color: var(--gray-500);">
                                <?= $site['next_run_at'] ? blc_time_ago($site['next_run_at']) : 'Not scheduled' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Direct Database Scan -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header"><h3>Direct Database Scan</h3></div>
        <div class="card-body">
            <p style="font-size: 13px; color: var(--gray-600); margin-bottom: 16px;">
                For WordPress sites on this server, you can scan directly from the database instead of HTTP-crawling.
                This is much faster and puts zero load on Apache. Fill in the database credentials for any site to enable it — leave empty to use HTTP crawl.
            </p>
            <?php foreach ($sites as $s): ?>
            <div style="border: 1px solid var(--gray-200); border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h4 style="font-size: 14px; font-weight: 600; margin: 0;"><?= htmlspecialchars($s['name']) ?></h4>
                    <?php if (!empty($s['wp_db_name']) && !empty($s['wp_db_user'])): ?>
                        <span class="badge badge-success" style="font-size: 11px;">Database mode</span>
                    <?php else: ?>
                        <span class="badge badge-light" style="font-size: 11px;">HTTP crawl</span>
                    <?php endif; ?>
                </div>
                <div class="settings-grid">
                    <div class="form-group">
                        <label class="form-label">DB Host</label>
                        <input type="text" name="wpdb[<?= $s['id'] ?>][host]" class="form-control" value="<?= htmlspecialchars($s['wp_db_host'] ?? 'localhost') ?>" placeholder="localhost">
                    </div>
                    <div class="form-group">
                        <label class="form-label">DB Name</label>
                        <input type="text" name="wpdb[<?= $s['id'] ?>][name]" class="form-control" value="<?= htmlspecialchars($s['wp_db_name'] ?? '') ?>" placeholder="wp_database">
                    </div>
                    <div class="form-group">
                        <label class="form-label">DB User</label>
                        <input type="text" name="wpdb[<?= $s['id'] ?>][user]" class="form-control" value="<?= htmlspecialchars($s['wp_db_user'] ?? '') ?>" placeholder="wp_user">
                    </div>
                    <div class="form-group">
                        <label class="form-label">DB Password</label>
                        <input type="password" name="wpdb[<?= $s['id'] ?>][pass]" class="form-control" value="<?= htmlspecialchars($s['wp_db_pass'] ?? '') ?>" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Table Prefix</label>
                        <input type="text" name="wpdb[<?= $s['id'] ?>][prefix]" class="form-control" value="<?= htmlspecialchars($s['wp_table_prefix'] ?? 'wp_') ?>" placeholder="wp_">
                    </div>
                </div>
                <div style="margin-top: 8px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="testWpDb(<?= $s['id'] ?>)">Test Connection</button>
                    <span id="wpdb-result-<?= $s['id'] ?>" style="margin-left: 8px; font-size: 12px;"></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header"><h3>Cron Setup</h3></div>
        <div class="card-body">
            <p style="font-size: 13px; color: var(--gray-600); margin-bottom: 12px;">Add this cron job to your server (via cPanel or command line):</p>
            <pre style="background: var(--gray-100); padding: 12px; border-radius: 6px; font-size: 12px; overflow-x: auto;">*/5 * * * * /usr/bin/php <?= htmlspecialchars(BLC_ROOT) ?>/cron/run-scheduled.php >> <?= htmlspecialchars(BLC_ROOT) ?>/data/logs/scanner.log 2>&1</pre>
            <div class="form-hint" style="margin-top: 8px;">This checks for due scans every 5 minutes and launches them in the background.</div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary" style="margin-bottom: 24px;">Save Settings</button>
</form>

<script>
function testWpDb(siteId) {
    const form = document.querySelector('form');
    const formData = new FormData(form);
    const config = {
        host: formData.get(`wpdb[${siteId}][host]`) || 'localhost',
        name: formData.get(`wpdb[${siteId}][name]`),
        user: formData.get(`wpdb[${siteId}][user]`),
        pass: formData.get(`wpdb[${siteId}][pass]`),
        prefix: formData.get(`wpdb[${siteId}][prefix]`) || 'wp_',
    };
    const el = document.getElementById(`wpdb-result-${siteId}`);
    el.textContent = 'Testing...';
    el.style.color = 'var(--gray-500)';

    fetch('api/test-wp-db.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({site_id: siteId, ...config}),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            el.textContent = `Connected! ${data.post_count} published posts found.`;
            el.style.color = 'var(--success)';
        } else {
            el.textContent = `Failed: ${data.error}`;
            el.style.color = 'var(--danger)';
        }
    })
    .catch(err => {
        el.textContent = `Error: ${err.message}`;
        el.style.color = 'var(--danger)';
    });
}
</script>
