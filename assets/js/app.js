/**
 * Broken Link Checker - Core JavaScript
 */

const BLC = {
    apiBase: 'api/',

    async fetch(endpoint, options = {}) {
        const url = this.apiBase + endpoint;
        const defaults = {
            headers: { 'Content-Type': 'application/json' },
        };
        const config = { ...defaults, ...options };

        try {
            const response = await fetch(url, config);
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }
            return data;
        } catch (err) {
            if (err.message === 'Failed to fetch') {
                throw new Error('Network error - please check your connection');
            }
            throw err;
        }
    },

    toast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            toast.style.transition = 'all 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    },

    async startScan(siteId) {
        try {
            const data = await this.fetch('scan-start.php', {
                method: 'POST',
                body: JSON.stringify({ site_id: siteId }),
            });
            this.toast('Scan started!', 'success');
            this.pollScanProgress(data.scan_id, siteId);
            return data;
        } catch (err) {
            this.toast('Failed to start scan: ' + err.message, 'error');
            throw err;
        }
    },

    async stopScan(scanId) {
        try {
            await this.fetch('scan-stop.php', {
                method: 'POST',
                body: JSON.stringify({ scan_id: scanId }),
            });
            this.toast('Scan cancelled', 'info');
        } catch (err) {
            this.toast('Failed to stop scan: ' + err.message, 'error');
        }
    },

    pollScanProgress(scanId, siteId) {
        const overlay = document.createElement('div');
        overlay.className = 'scan-overlay';
        overlay.id = 'scan-progress-overlay';
        overlay.innerHTML = `
            <div class="scan-modal">
                <h3>Scanning...</h3>
                <div class="progress-container">
                    <div class="progress-bar-wrapper">
                        <div class="progress-bar" id="scan-progress-bar" style="width: 0%"></div>
                    </div>
                    <div class="progress-info">
                        <span id="scan-progress-text">Starting scan...</span>
                        <span id="scan-progress-pct">0%</span>
                    </div>
                </div>
                <div style="margin-top: 16px; display: flex; gap: 12px; justify-content: space-between; align-items: center;">
                    <div id="scan-stats" style="font-size: 12px; color: #6b7280;"></div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-outline btn-sm" onclick="BLC.dismissScanModal()">Background</button>
                        <button class="btn btn-danger btn-sm" onclick="BLC.stopScan(${scanId})">Cancel</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        this._scanPollInterval = setInterval(async () => {
            try {
                const data = await this.fetch(`scan-status.php?scan_id=${scanId}`);
                const bar = document.getElementById('scan-progress-bar');
                const text = document.getElementById('scan-progress-text');
                const pct = document.getElementById('scan-progress-pct');
                const stats = document.getElementById('scan-stats');

                if (bar && data.pages_crawled !== undefined) {
                    const total = data.pages_total || Math.max(data.pages_crawled * 2, 100);
                    const progress = Math.min(95, (data.pages_crawled / total) * 100);
                    bar.style.width = progress + '%';
                    if (pct) pct.textContent = Math.round(progress) + '%';
                }

                if (text) {
                    text.textContent = `Pages: ${data.pages_crawled || 0} | Links: ${data.links_checked || 0}`;
                }

                if (stats) {
                    stats.textContent = `Broken: ${data.links_broken || 0} | Warnings: ${data.links_warning || 0}`;
                }

                if (data.status === 'completed' || data.status === 'failed' || data.status === 'cancelled') {
                    clearInterval(this._scanPollInterval);
                    this.dismissScanModal();

                    if (data.status === 'completed') {
                        this.toast('Scan completed! Refreshing...', 'success');
                    } else if (data.status === 'failed') {
                        this.toast('Scan failed: ' + (data.error_message || 'Unknown error'), 'error');
                    } else {
                        this.toast('Scan cancelled', 'info');
                    }

                    setTimeout(() => {
                        window.location.href = `index.php?page=site-detail&site_id=${siteId}`;
                    }, 1500);
                }
            } catch (err) {
                console.error('Poll error:', err);
            }
        }, 3000);
    },

    dismissScanModal() {
        const overlay = document.getElementById('scan-progress-overlay');
        if (overlay) overlay.remove();
    },

    async ignoreLink(targetUrl, reason, siteId) {
        try {
            await this.fetch('ignore.php', {
                method: 'POST',
                body: JSON.stringify({
                    pattern: targetUrl,
                    pattern_type: 'exact',
                    reason: reason || '',
                    site_id: siteId || null,
                }),
            });
            this.toast('Link added to ignore list', 'success');
            return true;
        } catch (err) {
            this.toast('Failed to ignore link: ' + err.message, 'error');
            return false;
        }
    },

    async removeIgnore(ignoreId) {
        try {
            await this.fetch('ignore.php?id=' + ignoreId, {
                method: 'DELETE',
            });
            this.toast('Removed from ignore list', 'success');
            return true;
        } catch (err) {
            this.toast('Failed: ' + err.message, 'error');
            return false;
        }
    },

    async rescanPage(sourceUrl, siteId) {
        try {
            this.toast('Re-scanning page...', 'info');
            const data = await this.fetch('rescan-page.php', {
                method: 'POST',
                body: JSON.stringify({ source_url: sourceUrl, site_id: siteId }),
            });
            const parts = [];
            if (data.removed > 0) parts.push(`${data.removed} removed`);
            if (data.added > 0) parts.push(`${data.added} new`);
            if (data.rechecked > 0) parts.push(`${data.rechecked} rechecked`);
            this.toast('Page re-scanned: ' + (parts.length ? parts.join(', ') : 'no changes'), 'success');
            return data;
        } catch (err) {
            this.toast('Re-scan failed: ' + err.message, 'error');
            return null;
        }
    },

    async recheckLink(linkId) {
        try {
            const data = await this.fetch('recheck.php', {
                method: 'POST',
                body: JSON.stringify({ link_id: linkId }),
            });
            this.toast('Link rechecked', 'success');
            return data;
        } catch (err) {
            this.toast('Recheck failed: ' + err.message, 'error');
            return null;
        }
    },

    async bulkAction(action, linkIds) {
        try {
            const data = await this.fetch('bulk-action.php', {
                method: 'POST',
                body: JSON.stringify({ action, link_ids: linkIds }),
            });
            this.toast(`${action} completed for ${linkIds.length} link(s)`, 'success');
            return data;
        } catch (err) {
            this.toast('Bulk action failed: ' + err.message, 'error');
            return null;
        }
    },

    initBulkActions() {
        const selectAll = document.getElementById('select-all');
        const bulkBar = document.getElementById('bulk-bar');
        const countEl = document.getElementById('selected-count');

        if (!selectAll) return;

        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                cb.checked = this.checked;
            });
            BLC.updateBulkBar();
        });

        document.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.addEventListener('change', () => BLC.updateBulkBar());
        });
    },

    updateBulkBar() {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        const bar = document.getElementById('bulk-bar');
        const count = document.getElementById('selected-count');

        if (bar) {
            if (checked.length > 0) {
                bar.classList.add('show');
                if (count) count.textContent = `${checked.length} selected`;
            } else {
                bar.classList.remove('show');
            }
        }
    },

    getSelectedIds() {
        return Array.from(document.querySelectorAll('.row-checkbox:checked'))
            .map(cb => parseInt(cb.value));
    },

    async bulkIgnore() {
        const ids = this.getSelectedIds();
        if (ids.length === 0) return;
        const result = await this.bulkAction('ignore', ids);
        if (result) setTimeout(() => location.reload(), 1000);
    },

    async bulkRecheck() {
        const ids = this.getSelectedIds();
        if (ids.length === 0) return;
        const result = await this.bulkAction('recheck', ids);
        if (result) setTimeout(() => location.reload(), 1000);
    },

    showIgnoreDialog(url, siteId) {
        const reason = prompt('Reason for ignoring this link (optional):');
        if (reason !== null) {
            this.ignoreLink(url, reason, siteId).then(ok => {
                if (ok) setTimeout(() => location.reload(), 800);
            });
        }
    },
};

document.addEventListener('DOMContentLoaded', function() {
    BLC.initBulkActions();

    document.querySelectorAll('.dropdown-toggle').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const menu = this.nextElementSibling;
            document.querySelectorAll('.dropdown-menu.show').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
            menu.classList.toggle('show');
        });
    });

    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    });
});
