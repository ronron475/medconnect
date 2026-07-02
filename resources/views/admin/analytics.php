<?php
session_start();
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

$stats = [
    'total_appointments' => (int) $pdo->query("SELECT COUNT(*) FROM consultations")->fetchColumn(),
    'completed_consults' => (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE status='completed'")->fetchColumn(),
    'pending_triage'     => $pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()
        ? (int) $pdo->query("SELECT COUNT(*) FROM triage_results WHERE level IN ('1','2','high','emergency') OR urgency_label LIKE '%Urgent%'")->fetchColumn() : 0,
    'active_providers'   => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='provider' AND is_active=1")->fetchColumn(),
];

require_once __DIR__ . '/partials/layout_open.php';
?>

<?php require VIEWS_PATH . '/partials/admin_dashboard_charts.php'; ?>

<div class="header-row" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;">
    <div>
        <h2 class="text-h2">Operational Reports</h2>
        <p class="text-muted">Generate and export system performance data, appointment summaries, and user statistics.</p>
    </div>
    <div style="display: flex; gap: 12px;">
        <button onclick="exportReport('appointments')" class="mc-btn mc-btn--primary">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV Report
        </a>
    </div>
</div>

<div class="stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 24px; margin-bottom: 32px;">
    <?php foreach($stats as $key => $val): ?>
    <div class="mc-card" style="text-align: center;">
        <div class="text-xs text-muted mb-xs" style="text-transform: uppercase; font-weight: 800;"><?= str_replace('_', ' ', $key) ?></div>
        <div class="text-h1" style="color: var(--mc-navy-dark);"><?= number_format($val) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="mc-card">
    <h3 class="text-h3 mb-md">Available Report Modules</h3>
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
        
        <div style="padding: 20px; border-radius: 12px; border: 1px solid var(--mc-border-thin); background: var(--mc-ice-blue);">
            <div style="font-weight: 800; color: var(--mc-navy-dark); margin-bottom: 8px;">Appointment Summary</div>
            <p class="text-xs text-muted mb-md">Complete list of all consultations, provider assignments, and completion status.</p>
            <button onclick="exportReport('appointments')" class="mc-btn mc-btn--outline" style="width: 100%; background: #fff;">Download CSV</button>
        </div>

        <div style="padding: 20px; border-radius: 12px; border: 1px solid var(--mc-border-thin); background: var(--mc-ice-blue);">
            <div style="font-weight: 800; color: var(--mc-navy-dark); margin-bottom: 8px;">User Demographics</div>
            <p class="text-xs text-muted mb-md">Breakdown of registered patients by age, gender, and barangay sector.</p>
            <button onclick="exportReport('users')" class="mc-btn mc-btn--outline" style="width: 100%; background: #fff;">Download CSV</button>
        </div>

        <div style="padding: 20px; border-radius: 12px; border: 1px solid var(--mc-border-thin); background: var(--mc-ice-blue);">
            <div style="font-weight: 800; color: var(--mc-navy-dark); margin-bottom: 8px;">System Audit Snapshot</div>
            <p class="text-xs text-muted mb-md">Condensed log of all security-related actions for the current billing cycle.</p>
            <button onclick="exportReport('audit')" class="mc-btn mc-btn--outline" style="width: 100%; background: #fff;">Download CSV</button>
        </div>

    </div>
</div>

<script>
function exportReport(type) {
    window.location.href = `<?= ASSET_BASE ?>/app/api/admin/export_report.php?type=${type}`;
}
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
