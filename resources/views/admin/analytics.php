<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Operational Reports & Analytics';

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="mc-card" style="padding: 14px 16px;">
    <h3 class="text-h3 mb-md" style="margin-bottom: 10px;">Available Report Modules</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
        
        <div style="padding: 14px; border-radius: 12px; border: 1px solid var(--mc-border-thin); background: var(--mc-ice-blue);">
            <div style="font-weight: 800; color: var(--mc-navy-dark); margin-bottom: 6px;">Appointment Summary</div>
            <p class="text-xs text-muted mb-md" style="margin-bottom: 10px;">Complete list of all consultations, provider assignments, and completion status.</p>
            <button onclick="exportReport('appointments')" class="mc-btn mc-btn--outline mc-btn--info" style="width: 100%;">Download CSV</button>
        </div>

        <div style="padding: 14px; border-radius: 12px; border: 1px solid var(--mc-border-thin); background: var(--mc-ice-blue);">
            <div style="font-weight: 800; color: var(--mc-navy-dark); margin-bottom: 6px;">User Demographics</div>
            <p class="text-xs text-muted mb-md" style="margin-bottom: 10px;">Breakdown of registered patients by age, gender, and barangay sector.</p>
            <button onclick="exportReport('users')" class="mc-btn mc-btn--outline mc-btn--info" style="width: 100%;">Download CSV</button>
        </div>

        <div style="padding: 14px; border-radius: 12px; border: 1px solid var(--mc-border-thin); background: var(--mc-ice-blue);">
            <div style="font-weight: 800; color: var(--mc-navy-dark); margin-bottom: 6px;">System Audit Snapshot</div>
            <p class="text-xs text-muted mb-md" style="margin-bottom: 10px;">Condensed log of all security-related actions for the current billing cycle.</p>
            <button onclick="exportReport('audit')" class="mc-btn mc-btn--outline mc-btn--info" style="width: 100%;">Download CSV</button>
        </div>

    </div>
</div>

<script>
function exportReport(type) {
    window.location.href = `<?= ASSET_BASE ?>/app/api/admin/export_report.php?type=${type}`;
}
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
