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
$recentActivities = superadmin_recent_activities($pdo, 8);
$recentLogins = superadmin_recent_logins($pdo, 6);
$health = superadmin_system_health($pdo);

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

<?php require VIEWS_PATH . '/partials/admin_dashboard_charts.php'; ?>

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
    </aside>
</div>

</div><!-- /data-live-dashboard -->

<script src="<?= ASSET_BASE ?>/assets/js/admin-dashboard-live.js?v=<?= $admLiveJsVer ?>"></script>
<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
