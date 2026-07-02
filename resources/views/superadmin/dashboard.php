<?php
/**
 * Super Admin — enterprise platform dashboard.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/superadmin/service.php';
require_once BASE_PATH . '/app/includes/doctor_application_schema.php';
require_once BASE_PATH . '/app/includes/bhw_application_schema.php';

doctor_application_ensure_schema($pdo);
bhw_application_ensure_schema($pdo);

$page_title = 'Super Admin Dashboard';
$stats = superadmin_dashboard_stats($pdo);
$security = superadmin_get_security_summary($pdo);
$recentActivities = superadmin_recent_activities($pdo, 8);
$recentLogins = superadmin_recent_logins($pdo, 6);
$health = superadmin_system_health($pdo);

$pending_doctor_approvals = (int) $pdo->query("SELECT COUNT(*) FROM doctor_applications WHERE status='pending_approval'")->fetchColumn();
$pending_bhw_approvals    = (int) $pdo->query("SELECT COUNT(*) FROM bhw_applications WHERE status='pending_approval'")->fetchColumn();
$pending_checker_total    = $pending_doctor_approvals + $pending_bhw_approvals;

$super_stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
$super_stmt->execute([(int) ($_SESSION['user_id'] ?? 0)]);
$super_row = $super_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$healthClass = match ($stats['system_health']) {
    'critical' => 'critical',
    'warning'  => 'warning',
    default    => 'healthy',
};

require_once __DIR__ . '/partials/layout_open.php';
?>

<section class="adm-banner superadmin-banner" aria-label="Welcome">
    <div class="adm-banner-inner">
        <div class="adm-banner-eyebrow">Super Admin Control Center</div>
        <h1 class="adm-banner-title">Welcome, <?= htmlspecialchars($super_row['first_name'] ?? 'Super Admin') ?></h1>
        <p class="adm-banner-sub">Enterprise governance, security monitoring, and Maker-Checker approvals for healthcare account provisioning.</p>
    </div>
    <div class="superadmin-banner-actions">
        <span class="mc-badge mc-badge--super">Super Administrator</span>
        <span class="superadmin-health-pill superadmin-health-pill--<?= htmlspecialchars($healthClass) ?>">System <?= htmlspecialchars(strtoupper($stats['system_health'])) ?></span>
        <?php if ($pending_checker_total > 0): ?>
        <a href="<?= ASSET_BASE ?>/views/superadmin/doctor_approvals.php" class="adm-card-head-action superadmin-urgent-pill">
            <?= $pending_checker_total ?> approval<?= $pending_checker_total === 1 ? '' : 's' ?> pending
        </a>
        <?php endif; ?>
    </div>
</section>

<?php if ($pending_checker_total > 0): ?>
<section class="superadmin-approval-strip" aria-label="Pending approvals">
    <?php if ($pending_doctor_approvals > 0): ?>
    <a href="<?= ASSET_BASE ?>/views/superadmin/doctor_approvals.php" class="superadmin-approval-card">
        <strong><?= $pending_doctor_approvals ?></strong>
        <span>Doctor<?= $pending_doctor_approvals === 1 ? '' : 's' ?> awaiting approval</span>
    </a>
    <?php endif; ?>
    <?php if ($pending_bhw_approvals > 0): ?>
    <a href="<?= ASSET_BASE ?>/views/superadmin/bhw_approvals.php" class="superadmin-approval-card">
        <strong><?= $pending_bhw_approvals ?></strong>
        <span>BHW<?= $pending_bhw_approvals === 1 ? '' : 's' ?> awaiting approval</span>
    </a>
    <?php endif; ?>
</section>
<?php endif; ?>

<section aria-label="Live operations">
    <div class="adm-section-head">
        <h2 class="adm-section-title">Live Operations</h2>
        <p class="adm-section-sub">Real-time platform activity</p>
    </div>
    <?php $notif_widget_mode = 'strip'; require VIEWS_PATH . '/partials/notification_widgets.php'; ?>
</section>

<?php require VIEWS_PATH . '/partials/admin_dashboard_charts.php'; ?>

<section aria-label="Platform metrics">
    <div class="adm-section-head">
        <h2 class="adm-section-title">Platform Metrics</h2>
        <p class="adm-section-sub">Key totals at a glance</p>
    </div>
    <div class="superadmin-stat-grid superadmin-stat-grid--compact">
        <div class="mc-card superadmin-stat-card"><div class="text-h1"><?= (int) $stats['total_patients'] ?></div><div class="text-xs text-muted">Patients</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1"><?= (int) $stats['total_providers'] ?></div><div class="text-xs text-muted">Doctors</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1"><?= (int) $stats['total_consultations'] ?></div><div class="text-xs text-muted">Consultations</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1" style="color:<?= (int) $stats['emergency_cases'] > 0 ? '#ef233c' : 'inherit' ?>;"><?= (int) $stats['emergency_cases'] ?></div><div class="text-xs text-muted">Emergency Cases</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1"><?= (int) $stats['total_barangays'] ?></div><div class="text-xs text-muted">Barangays</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1"><?= (int) $stats['total_facilities'] ?></div><div class="text-xs text-muted">Facilities</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1"><?= (int) $security['failed24h'] ?></div><div class="text-xs text-muted">Failed Logins (24h)</div></div>
        <div class="mc-card superadmin-stat-card"><div class="text-h1"><?= (int) $security['activeSessions'] ?></div><div class="text-xs text-muted">Active Sessions</div></div>
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
                    <tbody>
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
                    <tbody>
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
            <div class="adm-actions-body" style="padding-top:0;">
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
                <a href="<?= ASSET_BASE ?>/views/superadmin/doctor_approvals.php" class="adm-action-btn adm-action-btn--primary">
                    Doctor Approval Queue
                    <?php if ($pending_doctor_approvals > 0): ?><span class="adm-pending-badge"><?= $pending_doctor_approvals ?></span><?php endif; ?>
                </a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/bhw_approvals.php" class="adm-action-btn adm-action-btn--outline">
                    BHW Approval Queue
                    <?php if ($pending_bhw_approvals > 0): ?><span class="adm-pending-badge"><?= $pending_bhw_approvals ?></span><?php endif; ?>
                </a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/administrators.php" class="adm-action-btn adm-action-btn--outline">Manage Administrators</a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/security_dashboard.php" class="adm-action-btn adm-action-btn--outline">Security Center</a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/backup.php" class="adm-action-btn adm-action-btn--outline">Database Backup</a>
                <a href="<?= ASSET_BASE ?>/views/superadmin/system_settings.php" class="adm-action-btn adm-action-btn--outline">System Settings</a>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
