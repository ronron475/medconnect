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
require_once BASE_PATH . '/app/includes/doctor_application_schema.php';
require_once BASE_PATH . '/app/includes/bhw_application_schema.php';

doctor_application_ensure_schema($pdo);
bhw_application_ensure_schema($pdo);

$page_title = 'System Overview';

$admin_id = (int) ($_SESSION['user_id'] ?? 0);
$admin_stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
$admin_stmt->execute([$admin_id]);
$admin_row = $admin_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$admin_display = trim(($admin_row['first_name'] ?? 'Administrator') . ' ' . ($admin_row['last_name'] ?? ''));

// Platform metrics
$total_users     = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$total_patients  = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='patient'")->fetchColumn();
$total_providers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='provider'")->fetchColumn();
$total_bhw       = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='bhw'")->fetchColumn();
$active_users    = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn();

$has_consults = $pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount() > 0;
$consults_today = $has_consults
    ? (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE consult_date = CURDATE()")->fetchColumn() : 0;
$active_sessions = $has_consults
    ? (int) $pdo->query("SELECT COUNT(*) FROM consultations WHERE status='in_consultation'")->fetchColumn() : 0;

$urgent_triage = $pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()
    ? (int) $pdo->query("SELECT COUNT(*) FROM triage_results WHERE level IN ('1','2') OR urgency_label LIKE '%Urgent%'")->fetchColumn() : 0;

$pending_doctor_apps = (int) $pdo->query("SELECT COUNT(*) FROM doctor_applications WHERE status='pending_approval'")->fetchColumn();
$pending_bhw_apps    = (int) $pdo->query("SELECT COUNT(*) FROM bhw_applications WHERE status='pending_approval'")->fetchColumn();
$pending_approvals   = $pending_doctor_apps + $pending_bhw_apps;

$stmt = $pdo->prepare("SELECT COUNT(*) FROM doctor_applications WHERE created_by = ? AND status IN ('draft','requires_documents','rejected')");
$stmt->execute([$admin_id]);
$my_draft_doctor = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM bhw_applications WHERE created_by = ? AND status IN ('draft','requires_documents','rejected')");
$stmt->execute([$admin_id]);
$my_draft_bhw = (int) $stmt->fetchColumn();

$recent_users = $pdo->query(
    "SELECT id, first_name, last_name, email, role, is_active, created_at
     FROM users ORDER BY created_at DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);

$role_labels = [
    'patient'  => 'Patient',
    'provider' => 'Doctor',
    'bhw'      => 'BHW',
    'admin'    => 'Admin',
    'superadmin' => 'Super Admin',
];

require_once __DIR__ . '/partials/layout_open.php';
?>

<!-- Welcome -->
<section class="adm-banner" aria-label="Welcome">
    <div class="adm-banner-inner">
        <div class="adm-banner-eyebrow">System Overview</div>
        <h1 class="adm-banner-title">Welcome back, <?= htmlspecialchars($admin_row['first_name'] ?? 'Administrator') ?></h1>
        <p class="adm-banner-sub">Monitor platform activity, manage healthcare operations, and submit account applications for Super Administrator approval.</p>
    </div>
</section>

<!-- Live operations (notification-driven) -->
<section aria-label="Live operations">
    <div class="adm-section-head">
        <h2 class="adm-section-title">Live Operations</h2>
        <p class="adm-section-sub">Real-time counts from consultations, referrals, and alerts</p>
    </div>
    <?php $notif_widget_mode = 'strip'; require VIEWS_PATH . '/partials/notification_widgets.php'; ?>
</section>

<?php require VIEWS_PATH . '/partials/admin_dashboard_charts.php'; ?>

<!-- Platform snapshot -->
<section aria-label="Platform metrics">
    <div class="adm-section-head">
        <h2 class="adm-section-title">Platform Snapshot</h2>
        <p class="adm-section-sub">Registered users and clinical workload at a glance</p>
    </div>
    <div class="adm-metrics">
        <div class="adm-metric">
            <div class="adm-metric-icon adm-metric-icon--blue">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
            </div>
            <div class="adm-metric-body">
                <div class="adm-metric-value"><?= $total_patients ?></div>
                <div class="adm-metric-label">Patients</div>
                <div class="adm-metric-sub"><?= $total_users ?> total users</div>
            </div>
        </div>
        <div class="adm-metric">
            <div class="adm-metric-icon adm-metric-icon--indigo">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
            </div>
            <div class="adm-metric-body">
                <div class="adm-metric-value"><?= $total_providers ?></div>
                <div class="adm-metric-label">Doctors</div>
                <div class="adm-metric-sub"><?= $total_bhw ?> BHW active</div>
            </div>
        </div>
        <div class="adm-metric">
            <div class="adm-metric-icon adm-metric-icon--teal">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="adm-metric-body">
                <div class="adm-metric-value"><?= $consults_today ?></div>
                <div class="adm-metric-label">Consultations Today</div>
                <div class="adm-metric-sub"><?= $active_sessions ?> in session now</div>
            </div>
        </div>
        <div class="adm-metric adm-metric--urgent">
            <div class="adm-metric-icon adm-metric-icon--red">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div class="adm-metric-body">
                <div class="adm-metric-value" style="color:#dc2626;"><?= $urgent_triage ?></div>
                <div class="adm-metric-label">Urgent Triage</div>
                <div class="adm-metric-sub">Requires attention</div>
            </div>
        </div>
    </div>
</section>

<!-- Main content -->
<div class="adm-grid">
    <div class="adm-grid-main">
        <div class="adm-card">
            <div class="adm-card-head">
                <div class="adm-card-head-icon adm-card-head-icon--indigo">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                </div>
                <div>
                    <div class="adm-card-head-title">Recent Registrations</div>
                    <div class="adm-card-head-sub">Latest accounts on the platform</div>
                </div>
                <a href="<?= ASSET_BASE ?>/views/admin/user_management.php" class="adm-card-head-action">View all</a>
            </div>
            <div class="adm-card-body adm-table-wrap">
                <table class="adm-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recent_users)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:32px;color:#94a3b8;">No registrations yet.</td></tr>
                    <?php else: foreach ($recent_users as $u):
                        $initials = strtoupper(substr($u['first_name'], 0, 1) . substr($u['last_name'], 0, 1));
                        $role_key = $u['role'];
                        $role_class = match ($role_key) {
                            'patient' => 'adm-role-badge--patient',
                            'provider' => 'adm-role-badge--provider',
                            'admin' => 'adm-role-badge--admin',
                            default => 'adm-role-badge--default',
                        };
                    ?>
                        <tr>
                            <td>
                                <div class="adm-user-cell">
                                    <div class="adm-user-avatar"><?= htmlspecialchars($initials) ?></div>
                                    <div>
                                        <div class="adm-user-name"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></div>
                                        <div class="adm-user-email"><?= htmlspecialchars($u['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="adm-role-badge <?= $role_class ?>"><?= htmlspecialchars($role_labels[$role_key] ?? ucfirst($role_key)) ?></span></td>
                            <td>
                                <span class="adm-status-badge <?= $u['is_active'] ? 'adm-status-badge--active' : 'adm-status-badge--inactive' ?>">
                                    <span class="adm-status-dot"></span>
                                    <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td class="adm-date-cell"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <aside class="adm-grid-side">
        <?php $notif_widget_mode = 'recent'; require VIEWS_PATH . '/partials/notification_widgets.php'; ?>

        <?php if ($pending_approvals > 0 || $my_draft_doctor > 0 || $my_draft_bhw > 0): ?>
        <div class="adm-card">
            <div class="adm-card-head">
                <div class="adm-card-head-icon adm-card-head-icon--blue">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                </div>
                <div>
                    <div class="adm-card-head-title">Maker-Checker Queue</div>
                    <div class="adm-card-head-sub">Applications awaiting action</div>
                </div>
            </div>
            <div class="adm-actions-body">
                <?php if ($my_draft_doctor > 0): ?>
                <a href="<?= ASSET_BASE ?>/views/admin/doctor_applications.php" class="adm-pending-item">
                    <span><?= $my_draft_doctor ?> Doctor application<?= $my_draft_doctor === 1 ? '' : 's' ?> in progress</span>
                    <span class="adm-pending-badge">Draft</span>
                </a>
                <?php endif; ?>
                <?php if ($my_draft_bhw > 0): ?>
                <a href="<?= ASSET_BASE ?>/views/admin/bhw_applications.php" class="adm-pending-item">
                    <span><?= $my_draft_bhw ?> BHW application<?= $my_draft_bhw === 1 ? '' : 's' ?> in progress</span>
                    <span class="adm-pending-badge">Draft</span>
                </a>
                <?php endif; ?>
                <?php if ($pending_doctor_apps > 0): ?>
                <div class="adm-pending-item adm-pending-item--info">
                    <span><?= $pending_doctor_apps ?> Doctor<?= $pending_doctor_apps === 1 ? '' : 's' ?> pending Super Admin approval</span>
                </div>
                <?php endif; ?>
                <?php if ($pending_bhw_apps > 0): ?>
                <div class="adm-pending-item adm-pending-item--info">
                    <span><?= $pending_bhw_apps ?> BHW<?= $pending_bhw_apps === 1 ? '' : 's' ?> pending Super Admin approval</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="adm-card">
            <div class="adm-card-head">
                <div class="adm-card-head-icon adm-card-head-icon--blue">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                </div>
                <div>
                    <div class="adm-card-head-title">Quick Actions</div>
                    <div class="adm-card-head-sub">Common administrator tasks</div>
                </div>
            </div>
            <div class="adm-actions-body">
                <a href="<?= ASSET_BASE ?>/views/admin/doctor_applications.php" class="adm-action-btn adm-action-btn--primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="16" y1="11" x2="22" y2="11"/></svg>
                    Submit Doctor Application
                </a>
                <a href="<?= ASSET_BASE ?>/views/admin/bhw_applications.php" class="adm-action-btn adm-action-btn--outline">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    Submit BHW Application
                </a>
                <a href="<?= ASSET_BASE ?>/views/admin/user_management.php" class="adm-action-btn adm-action-btn--outline">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Manage Users
                </a>
                <a href="<?= ASSET_BASE ?>/views/admin/audit_logs.php" class="adm-action-btn adm-action-btn--outline">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Audit Logs
                </a>
            </div>
        </div>
    </aside>
</div>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
