<?php
/**
 * Super Admin — enterprise platform dashboard.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/service.php';
require_once BASE_PATH . '/app/includes/doctor_application_schema.php';
require_once BASE_PATH . '/app/includes/bhw_application_schema.php';
require_once BASE_PATH . '/app/includes/admin_dashboard_charts.php';

doctor_application_ensure_schema($pdo);
bhw_application_ensure_schema($pdo);

$page_title = 'Dashboard';
$stats = superadmin_dashboard_stats($pdo);
$security = superadmin_get_security_summary($pdo);
$recentActivities = superadmin_recent_activities($pdo, 8);
$recentLogins = superadmin_recent_logins($pdo, 6);
$health = superadmin_system_health($pdo);
$role_counts = admin_chart_role_counts_map($pdo);
$total_users_live = array_sum($role_counts);

$pending_doctor_approvals = (int) $pdo->query("SELECT COUNT(*) FROM doctor_applications WHERE status='pending_approval'")->fetchColumn();
$pending_bhw_approvals    = (int) $pdo->query("SELECT COUNT(*) FROM bhw_applications WHERE status='pending_approval'")->fetchColumn();
$pending_checker_total    = $pending_doctor_approvals + $pending_bhw_approvals;

require_once __DIR__ . '/partials/layout_open.php';
$admLiveJsVer = (int) @filemtime(ASSETS_PATH . '/js/admin-dashboard-live.js');
?>

<div data-live-dashboard="superadmin">

<section class="superadmin-approval-strip" data-live-approval-strip aria-label="Pending approvals"<?= $pending_checker_total > 0 ? '' : ' hidden' ?>>
    <?php if ($pending_doctor_approvals > 0): ?>
    <a href="<?= ASSET_BASE ?>/views/superadmin/doctor_applications.php?tab=pending" class="superadmin-approval-card">
        <strong><?= $pending_doctor_approvals ?></strong>
        <span>Doctor<?= $pending_doctor_approvals === 1 ? '' : 's' ?> awaiting approval</span>
    </a>
    <?php endif; ?>
    <?php if ($pending_bhw_approvals > 0): ?>
    <a href="<?= ASSET_BASE ?>/views/superadmin/bhw_applications.php?tab=pending" class="superadmin-approval-card">
        <strong><?= $pending_bhw_approvals ?></strong>
        <span>BHW<?= $pending_bhw_approvals === 1 ? '' : 's' ?> awaiting approval</span>
    </a>
    <?php endif; ?>
</section>

<section aria-label="Platform metrics">
    <div class="adm-section-head" style="display:flex;justify-content:space-between;align-items:flex-end;gap:8px;flex-wrap:wrap;">
        <div>
            <h2 class="adm-section-title">Platform Snapshot</h2>
            <p class="adm-section-sub">Registered users · auto-refreshes</p>
        </div>
    </div>
    <div class="adm-metrics">
        <div class="adm-metric adm-metric--patients">
            <div class="adm-metric-icon adm-metric-icon--blue">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div class="adm-metric-body">
                <div class="adm-metric-label">Patients</div>
                <div class="adm-metric-value" data-live-metric="patients"><?= (int) ($role_counts['patient'] ?? 0) ?></div>
                <div class="adm-metric-sub">Total Patients</div>
            </div>
        </div>
        <div class="adm-metric adm-metric--doctors">
            <div class="adm-metric-icon adm-metric-icon--green">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></svg>
            </div>
            <div class="adm-metric-body">
                <div class="adm-metric-label">Doctors</div>
                <div class="adm-metric-value" data-live-metric="providers"><?= (int) ($role_counts['provider'] ?? 0) ?></div>
                <div class="adm-metric-sub">Total Doctors</div>
            </div>
        </div>
        <div class="adm-metric adm-metric--bhw">
            <div class="adm-metric-icon adm-metric-icon--purple">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div class="adm-metric-body">
                <div class="adm-metric-label">BHWs</div>
                <div class="adm-metric-value" data-live-metric="bhw"><?= (int) ($role_counts['bhw'] ?? 0) ?></div>
                <div class="adm-metric-sub">Total BHWs</div>
            </div>
        </div>
    </div>
</section>

<?php require VIEWS_PATH . '/partials/admin_dashboard_charts.php'; ?>

<section aria-label="Platform operations metrics">
    <div class="adm-section-head">
        <h2 class="adm-section-title">Platform Metrics</h2>
        <p class="adm-section-sub">Key totals at a glance · auto-refreshes</p>
    </div>
    <div class="superadmin-stat-grid superadmin-stat-grid--compact">
        <div class="mc-card superadmin-stat-card"><div class="text-h1" data-live-metric="total_users"><?= (int) $total_users_live ?></div><div class="text-xs text-muted">Total Users</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1" data-live-metric="consultations"><?= (int) $stats['total_consultations'] ?></div><div class="text-xs text-muted">Consultations</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1" data-live-metric="emergency_cases" style="color:<?= (int) $stats['emergency_cases'] > 0 ? '#ef233c' : 'inherit' ?>;"><?= (int) $stats['emergency_cases'] ?></div><div class="text-xs text-muted">Emergency Cases</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1" data-live-metric="barangays"><?= (int) $stats['total_barangays'] ?></div><div class="text-xs text-muted">Barangays</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1" data-live-metric="facilities"><?= (int) $stats['total_facilities'] ?></div><div class="text-xs text-muted">Facilities</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1" data-live-metric="failed24h"><?= (int) $security['failed24h'] ?></div><div class="text-xs text-muted">Failed Logins (24h)</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1" data-live-metric="active_sessions"><?= (int) $security['activeSessions'] ?></div><div class="text-xs text-muted">Active Sessions</div></div>
    </div>
</section>

<div class="adm-grid superadmin-dashboard-grid">
    <div class="adm-grid-main">
        <div class="adm-card">
            <div class="adm-card-head">
                <div>
                    <div class="adm-card-head-title">Recent Activities</div>
                    <div class="adm-card-head-sub">Audit trail highlights</div>
                </div>
                <a href="<?= ASSET_BASE ?>/views/superadmin/audit_trail.php" class="adm-card-head-action">Audit trail</a>
            </div>
            <div class="adm-card-body adm-table-wrap">
                <table class="adm-table">
                    <thead><tr><th>User</th><th>Action</th><th>Module</th><th>Time</th></tr></thead>
                    <tbody data-live-activities>
                    <?php if (empty($recentActivities)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:28px;color:#94a3b8;">No recent activities.</td></tr>
                    <?php else: foreach ($recentActivities as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: 'System') ?></td>
                            <td><code class="text-xs"><?= htmlspecialchars($a['action'] ?? $a['action_type'] ?? '') ?></code></td>
                            <td><?= htmlspecialchars($a['module'] ?? 'system') ?></td>
                            <td class="adm-date-cell"><?= !empty($a['created_at']) ? date('M j, g:i A', strtotime($a['created_at'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="adm-card">
            <div class="adm-card-head">
                <div>
                    <div class="adm-card-head-title">Recent Logins</div>
                    <div class="adm-card-head-sub">Authentication events</div>
                </div>
                <a href="<?= ASSET_BASE ?>/views/superadmin/login_attempts.php" class="adm-card-head-action">Login attempts</a>
            </div>
            <div class="adm-card-body adm-table-wrap">
                <table class="adm-table">
                    <thead><tr><th>User</th><th>Role</th><th>IP</th><th>Time</th></tr></thead>
                    <tbody data-live-logins>
                    <?php if (empty($recentLogins)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:28px;color:#94a3b8;">No login events recorded.</td></tr>
                    <?php else: foreach ($recentLogins as $l): ?>
                        <tr>
                            <td><?= htmlspecialchars(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''))) ?></td>
                            <td><span class="adm-role-badge adm-role-badge--default"><?= strtoupper(htmlspecialchars($l['role'] ?? '')) ?></span></td>
                            <td class="text-xs"><?= htmlspecialchars($l['ip_address'] ?? '—') ?></td>
                            <td class="adm-date-cell"><?= date('M j, g:i A', strtotime($l['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <aside class="adm-grid-side">
        <?php $notif_widget_mode = 'recent'; require VIEWS_PATH . '/partials/notification_widgets.php'; ?>

        <div class="adm-card">
            <div class="adm-card-head">
                <div>
                    <div class="adm-card-head-title">Service Health</div>
                    <div class="adm-card-head-sub">Core platform services</div>
                </div>
            </div>
            <div class="adm-actions-body" style="padding-top:0;" data-live-health>
                <?php foreach ($health as $key => $svc):
                    if ($key === 'storage') continue;
                    $st = $svc['status'] ?? 'unknown';
                    $pill = in_array($st, ['healthy', 'online'], true) ? 'healthy' : ($st === 'disabled' ? 'warning' : ($st === 'critical' ? 'critical' : 'warning'));
                ?>
                <div class="flex-between" style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
                    <span class="text-sm"><?= htmlspecialchars($svc['label'] ?? $key) ?></span>
                    <span class="superadmin-health-pill superadmin-health-pill--<?= $pill ?>"><?= htmlspecialchars($st) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="adm-card">
            <div class="adm-card-head">
                <div>
                    <div class="adm-card-head-title">Checker Quick Actions</div>
                    <div class="adm-card-head-sub">Maker-Checker and governance</div>
                </div>
            </div>
            <div class="adm-actions-body">
                <a href="<?= ASSET_BASE ?>/views/superadmin/doctor_applications.php?tab=pending" class="adm-action-btn adm-action-btn--primary">
                    Doctors · Pending Approval
                    <span class="adm-pending-badge" data-live-badge-doctor-wrap<?= $pending_doctor_approvals > 0 ? '' : ' hidden' ?>><span data-live-badge-doctor><?= $pending_doctor_approvals ?></span></span>
                </a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/bhw_applications.php?tab=pending" class="adm-action-btn adm-action-btn--outline">
                    Barangay Health Workers
                    <span class="adm-pending-badge" data-live-badge-bhw-wrap<?= $pending_bhw_approvals > 0 ? '' : ' hidden' ?>><span data-live-badge-bhw><?= $pending_bhw_approvals ?></span></span>
                </a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/administrators.php" class="adm-action-btn adm-action-btn--outline">Manage Administrators</a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/security_dashboard.php" class="adm-action-btn adm-action-btn--outline">Security Center</a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/backup.php" class="adm-action-btn adm-action-btn--outline">Database Backup</a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/system_settings.php" class="adm-action-btn adm-action-btn--outline">System Settings</a>
            </div>
        </div>
    </aside>
</div>

</div><!-- /data-live-dashboard -->

<script src="<?= ASSET_BASE ?>/assets/js/admin-dashboard-live.js?v=<?= $admLiveJsVer ?>"></script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
