<?php
/**
 * Ignore List Management
 */

$pageTitle = 'Ignore List';
$currentPage = 'ignore-list';

$db = Database::getInstance();

// Get ignored URLs
$ignored = $db->query("SELECT i.*, s.name as site_name
    FROM ignored_urls i
    LEFT JOIN sites s ON i.site_id = s.id
    ORDER BY i.created_at DESC")->fetchAll();
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h1 style="font-size: 22px; font-weight: 700;">Ignore List</h1>
    <button class="btn btn-primary" onclick="showAddIgnoreForm()">Add Pattern</button>
</div>

<!-- Add form (hidden) -->
<div class="card" id="add-ignore-form" style="display: none; margin-bottom: 24px;">
    <div class="card-body">
        <h3 style="font-size: 15px; font-weight: 600; margin-bottom: 16px;">Add Ignore Pattern</h3>
        <form id="ignore-form" onsubmit="return submitIgnoreForm(event)">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">URL Pattern</label>
                    <input type="text" name="pattern" class="form-control" placeholder="https://example.com/broken-page" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Pattern Type</label>
                    <select name="pattern_type" class="form-control">
                        <option value="exact">Exact URL</option>
                        <option value="prefix">URL Prefix</option>
                        <option value="domain">Entire Domain</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Site (optional)</label>
                    <select name="site_id" class="form-control">
                        <option value="">All Sites (Global)</option>
                        <?php
                        $sites = $db->query('SELECT id, name FROM sites WHERE enabled = 1 ORDER BY name')->fetchAll();
                        foreach ($sites as $s):
                        ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason (optional)</label>
                    <input type="text" name="reason" class="form-control" placeholder="e.g., Requires login, known redirect">
                </div>
            </div>
            <div class="btn-group">
                <button type="submit" class="btn btn-primary">Add to Ignore List</button>
                <button type="button" class="btn btn-outline" onclick="document.getElementById('add-ignore-form').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Pattern</th>
                    <th>Type</th>
                    <th>Scope</th>
                    <th>Reason</th>
                    <th>Added By</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ignored)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:40px; color: var(--gray-400);">No ignored patterns yet</td></tr>
                <?php else: ?>
                    <?php foreach ($ignored as $item): ?>
                        <tr>
                            <td class="url-cell" title="<?= htmlspecialchars($item['pattern']) ?>">
                                <?= htmlspecialchars(blc_truncate($item['pattern'], 50)) ?>
                            </td>
                            <td><span class="badge badge-light"><?= htmlspecialchars($item['pattern_type']) ?></span></td>
                            <td><?= $item['site_name'] ? htmlspecialchars($item['site_name']) : '<em style="color:var(--gray-400)">Global</em>' ?></td>
                            <td><?= htmlspecialchars($item['reason'] ?? '') ?></td>
                            <td><?= htmlspecialchars($item['created_by'] ?? '-') ?></td>
                            <td><?= blc_time_ago($item['created_at']) ?></td>
                            <td>
                                <button class="btn btn-danger btn-sm" onclick="removeIgnore(<?= $item['id'] ?>)">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function showAddIgnoreForm() {
    document.getElementById('add-ignore-form').style.display = 'block';
    document.querySelector('#add-ignore-form input[name="pattern"]').focus();
}

async function submitIgnoreForm(e) {
    e.preventDefault();
    const form = e.target;
    const data = {
        pattern: form.pattern.value,
        pattern_type: form.pattern_type.value,
        site_id: form.site_id.value || null,
        reason: form.reason.value || null,
    };

    const ok = await BLC.ignoreLink(data.pattern, data.reason, data.site_id);
    if (ok) {
        setTimeout(() => location.reload(), 800);
    }
}

async function removeIgnore(id) {
    if (!confirm('Remove this pattern from the ignore list?')) return;
    const ok = await BLC.removeIgnore(id);
    if (ok) setTimeout(() => location.reload(), 800);
}
</script>
