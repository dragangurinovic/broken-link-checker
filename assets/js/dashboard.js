/**
 * Dashboard page JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Check for any running scans and show progress
    checkRunningScans();
});

async function checkRunningScans() {
    try {
        const data = await BLC.fetch('scan-status.php?check_running=1');
        if (data.running_scans && data.running_scans.length > 0) {
            data.running_scans.forEach(scan => {
                const card = document.querySelector(`[data-site-id="${scan.site_id}"]`);
                if (card) {
                    const scanBtn = card.querySelector('.scan-btn');
                    if (scanBtn) {
                        scanBtn.innerHTML = '<span class="spinner"></span> Scanning...';
                        scanBtn.disabled = true;
                    }
                }
            });
        }
    } catch (err) {
        console.error('Failed to check running scans:', err);
    }
}
