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
require_once BASE_PATH . '/app/includes/provider_verification.php';
require_once BASE_PATH . '/app/includes/user_account_status.php';
require_once BASE_PATH . '/app/includes/portal_paths.php';

require_once __DIR__ . '/_portal_access.php';

provider_verification_ensure_schema($pdo);
user_account_status_ensure_schema($pdo);

$role_filter = $_GET['role'] ?? 'provider';
if (portal_is_superadmin_shell() && $role_filter === 'admin') {
    header('Location: ' . portal_views_base() . '/administrators.php');
    exit;
}
$page_titles = ['bhw' => 'BHW Accounts', 'admin' => 'Administrator Accounts'];
$page_title = $page_titles[$role_filter] ?? 'Doctor Accounts';
$verify_filter = $_GET['verify'] ?? 'all';

$query = "
    SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.role, u.is_active, u.account_status, u.created_at,
           pp.prc_license_number, pp.verification_status, pp.rejection_note, pp.verified_at
    FROM users u
    LEFT JOIN provider_profiles pp ON pp.user_id = u.id
    WHERE u.role IN ('provider', 'admin', 'bhw')
";
$params = [];

if ($role_filter !== 'all') {
    $query .= ' AND u.role = ?';
    $params[] = $role_filter;
}

if ($role_filter === 'provider' && $verify_filter !== 'all') {
    $query .= ' AND pp.verification_status = ?';
    $params[] = $verify_filter;
}

$query .= ' ORDER BY u.created_at DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

$doctor_count = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'provider'")->fetchColumn();
$pending_count = (int) $pdo->query("SELECT COUNT(*) FROM provider_profiles pp JOIN users u ON u.id = pp.user_id WHERE u.role = 'provider' AND pp.verification_status = 'pending'")->fetchColumn();
$show_success = isset($_GET['created']) || isset($_GET['submitted']);
$show_verified = isset($_GET['verified']);
$show_rejected = isset($_GET['rejected']);
$is_superadmin = portal_is_superadmin();
$portalBase = portal_views_base();

require_once __DIR__ . '/partials/layout_open.php';
?>

<?php if ($show_success): ?>
<div class="mc-card" style="margin-bottom: 20px; border-left: 4px solid #16a34a; background: #f0fdf4;">
    <strong style="color: #15803d;">Doctor application submitted.</strong>
    <span class="text-muted" style="font-size: 13px;"> A Super Administrator will review the application before the account is activated.</span>
</div>
<?php endif; ?>
<?php if ($show_verified): ?>
<div class="mc-card" style="margin-bottom: 20px; border-left: 4px solid #16a34a; background: #f0fdf4;">
    <strong style="color: #15803d;">Doctor verified successfully.</strong>
</div>
<?php endif; ?>
<?php if ($show_rejected): ?>
<div class="mc-card" style="margin-bottom: 20px; border-left: 4px solid #dc2626; background: #fef2f2;">
    <strong style="color: #b91c1c;">Doctor verification rejected.</strong>
    <span class="text-muted" style="font-size: 13px;"> Account was deactivated.</span>
</div>
<?php endif; ?>

<?php if (!$is_superadmin): ?>
<div class="mc-card" style="margin-bottom: 20px; border-left: 4px solid #018a93; background: #f0fdfa;">
    <strong style="color: #0f766e;">Limited account status permissions.</strong>
    <span class="text-muted" style="font-size: 13px;"> You may <strong>archive</strong> staff accounts only. Approve, reject, activate, deactivate, and suspend require Super Administrator access.</span>
</div>
<?php endif; ?>

<div class="header-row" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <?php if ($role_filter === 'bhw'): ?>
        <h2 style="font-size: 24px; font-weight: 800; color: var(--mc-navy-deep); margin-bottom: 6px;">Barangay Health Worker Accounts</h2>
        <p style="color: var(--mc-text-muted); font-size: 14px; margin: 0;">
            Active BHW accounts approved via Maker-Checker workflow. New applications must be submitted for Super Administrator approval.
        </p>
        <?php else: ?>
        <h2 style="font-size: 24px; font-weight: 800; color: var(--mc-navy-deep); margin-bottom: 6px;">Doctor Accounts</h2>
        <p style="color: var(--mc-text-muted); font-size: 14px; margin: 0;">
            Active doctor accounts approved via Maker-Checker workflow. New applications must be submitted for Super Administrator approval.
            <strong><?= $doctor_count ?></strong> active doctor<?= $doctor_count === 1 ? '' : 's' ?>.
        </p>
        <?php endif; ?>
    </div>
    <?php if ($role_filter === 'bhw'): ?>
      <?php if ($is_superadmin): ?>
    <a href="<?= $portalBase ?>/bhw_approvals.php" class="mc-btn mc-btn--primary">BHW Approval Queue</a>
      <?php else: ?>
    <a href="<?= portal_view_url('bhw_applications.php') ?>" class="mc-btn mc-btn--primary">Create BHW Application</a>
      <?php endif; ?>
    <?php else: ?>
      <?php if ($is_superadmin): ?>
    <a href="<?= $portalBase ?>/doctor_approvals.php" class="mc-btn mc-btn--primary">Doctor Approval Queue</a>
      <?php else: ?>
    <button type="button" class="mc-btn mc-btn--primary" data-open-create-doctor>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Create Doctor Account
    </button>
      <?php endif; ?>
    <?php endif; ?>
</div>

<div style="display: flex; gap: 10px; margin-bottom: 12px; flex-wrap: wrap;">
    <a href="?role=provider" class="mc-btn <?= $role_filter === 'provider' ? 'mc-btn--primary' : 'mc-btn--outline' ?>" style="font-size: 12px;">Doctors</a>
    <a href="<?= $is_superadmin ? $portalBase . '/administrators.php' : '?role=admin' ?>" class="mc-btn <?= $role_filter === 'admin' ? 'mc-btn--primary' : 'mc-btn--outline' ?>" style="font-size: 12px;">Admins</a>
    <a href="?role=bhw" class="mc-btn <?= $role_filter === 'bhw' ? 'mc-btn--primary' : 'mc-btn--outline' ?>" style="font-size: 12px;">BHW</a>
    <a href="?role=all" class="mc-btn <?= $role_filter === 'all' ? 'mc-btn--primary' : 'mc-btn--outline' ?>" style="font-size: 12px;">All Staff</a>
</div>

<?php if ($role_filter === 'provider'): ?>
<div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
    <a href="?role=provider&verify=all" class="mc-btn <?= $verify_filter === 'all' ? 'mc-btn--primary' : 'mc-btn--outline' ?>" style="font-size: 12px;">All PRC Status</a>
    <a href="?role=provider&verify=pending" class="mc-btn <?= $verify_filter === 'pending' ? 'mc-btn--primary' : 'mc-btn--outline' ?>" style="font-size: 12px;">Pending</a>
    <a href="?role=provider&verify=verified" class="mc-btn <?= $verify_filter === 'verified' ? 'mc-btn--primary' : 'mc-btn--outline' ?>" style="font-size: 12px;">Verified</a>
    <a href="?role=provider&verify=rejected" class="mc-btn <?= $verify_filter === 'rejected' ? 'mc-btn--primary' : 'mc-btn--outline' ?>" style="font-size: 12px;">Rejected</a>
</div>
<?php endif; ?>

<div class="ch-section mc-card admin-card-accent">
    <div style="overflow-x: auto;">
        <table class="ch-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8fafc; text-align: left;">
                    <th style="padding: 14px 20px; font-size: 11px; text-transform: uppercase; color: var(--mc-text-muted);">Name</th>
                    <th style="padding: 14px 20px; font-size: 11px; text-transform: uppercase; color: var(--mc-text-muted);">Email</th>
                    <th style="padding: 14px 20px; font-size: 11px; text-transform: uppercase; color: var(--mc-text-muted);">PRC License</th>
                    <th style="padding: 14px 20px; font-size: 11px; text-transform: uppercase; color: var(--mc-text-muted);">Verification</th>
                    <th style="padding: 14px 20px; font-size: 11px; text-transform: uppercase; color: var(--mc-text-muted);">Account</th>
                    <th style="padding: 14px 20px; font-size: 11px; text-transform: uppercase; color: var(--mc-text-muted);">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $s): ?>
                <tr>
                    <td style="padding: 16px 20px; font-size: 14px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="ch-provider-avatar" style="background: var(--mc-blue); color: #fff;">
                                <?= strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1)) ?>
                            </div>
                            <div>
                                <strong><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></strong>
                                <div class="text-muted" style="font-size: 11px;"><?= $s['role'] === 'provider' ? 'Doctor' : ucfirst($s['role']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td style="padding: 16px 20px; font-size: 14px; color: var(--mc-text-muted);"><?= htmlspecialchars($s['email']) ?></td>
                    <td style="padding: 16px 20px; font-size: 13px;">
                        <?php if ($s['role'] === 'provider'): ?>
                            <?= $s['prc_license_number'] ? htmlspecialchars($s['prc_license_number']) : '<span class="text-muted">Not set</span>' ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 16px 20px;">
                        <?php if ($s['role'] === 'provider' && $s['verification_status']): ?>
                            <?php
                              $v = $s['verification_status'];
                              $vStyles = match ($v) {
                                  'verified' => ['#dcfce7', '#15803d', 'Verified'],
                                  'rejected' => ['#fee2e2', '#b91c1c', 'Rejected'],
                                  default => ['#fef3c7', '#b45309', 'Pending'],
                              };
                            ?>
                            <span class="mc-badge" style="background: <?= $vStyles[0] ?>; color: <?= $vStyles[1] ?>;" title="<?= htmlspecialchars($s['rejection_note'] ?? '') ?>">
                                <?= $vStyles[2] ?>
                            </span>
                            <?php if ($v === 'rejected' && !empty($s['rejection_note'])): ?>
                            <div class="text-muted" style="font-size: 11px; margin-top: 4px; max-width: 180px;"><?= htmlspecialchars($s['rejection_note']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 16px 20px;">
                        <?php
                          $effective = user_account_status_effective($s);
                          $acctBadge = AccountStatus::badge($effective);
                        ?>
                        <span class="mc-badge" style="background: <?= $acctBadge['bg'] ?>; color: <?= $acctBadge['color'] ?>;">
                            <?= htmlspecialchars($acctBadge['label']) ?>
                        </span>
                    </td>
                    <td style="padding: 16px 20px;">
                        <?php
                          $staff_actions = user_account_status_allowed_actions_for_role(
                              $effective,
                              $is_superadmin,
                              (string) ($s['role'] ?? '')
                          );
                          $staff_name = htmlspecialchars($s['first_name'] . ' ' . $s['last_name'], ENT_QUOTES);
                        ?>
                        <?php if ($s['role'] === 'provider' && $s['prc_license_number'] && $is_superadmin): ?>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <?php if ($s['verification_status'] !== 'verified'): ?>
                            <button type="button"
                                    class="mc-btn mc-btn--primary js-verify-doctor"
                                    style="padding: 6px 10px; font-size: 11px; cursor: pointer;"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-name="<?= $staff_name ?>"
                                    data-prc="<?= htmlspecialchars($s['prc_license_number'], ENT_QUOTES) ?>">
                                Verify
                            </button>
                            <?php endif; ?>
                            <?php if ($s['verification_status'] !== 'rejected'): ?>
                            <button type="button"
                                    class="mc-btn mc-btn--outline js-reject-doctor"
                                    style="padding: 6px 10px; font-size: 11px; color: #b91c1c; border-color: #fecaca; cursor: pointer;"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-name="<?= $staff_name ?>">
                                Reject
                            </button>
                            <?php endif; ?>
                            <?php foreach ($staff_actions as $act): ?>
                            <button type="button"
                                    class="mc-btn mc-btn--outline js-account-status-action"
                                    style="padding: 6px 10px; font-size: 11px; color: #b91c1c; border-color: #fecaca; cursor: pointer;"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-user-name="<?= $staff_name ?>"
                                    data-action="<?= htmlspecialchars($act) ?>">
                                <?= htmlspecialchars(user_account_status_action_label($act)) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif (!empty($staff_actions)): ?>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <?php foreach ($staff_actions as $act): ?>
                            <button type="button"
                                    class="mc-btn mc-btn--outline js-account-status-action"
                                    style="padding: 6px 10px; font-size: 11px; color: #b91c1c; border-color: #fecaca; cursor: pointer;"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-user-name="<?= $staff_name ?>"
                                    data-action="<?= htmlspecialchars($act) ?>">
                                <?= htmlspecialchars(user_account_status_action_label($act)) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif ($s['role'] === 'provider' && !$is_superadmin): ?>
                        <span class="mc-status-restricted" style="font-size: 12px; color: var(--mc-text-muted); max-width: 180px; display: inline-block;">Only the Super Administrator can perform this action.</span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size: 12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($staff)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--mc-text-muted); padding: 48px;">
                        No accounts found. Click <strong>Create Doctor Account</strong> to add a doctor.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
if (!$is_superadmin) {
    $create_doctor_show_role = true;
    $create_doctor_api = ASSET_BASE . '/app/api/admin/doctor_applications.php';
    $create_doctor_submit_label = 'Submit Application';
    require __DIR__ . '/partials/create_doctor_modal.php';
}
if ($is_superadmin) {
    require __DIR__ . '/partials/doctor_verify_modal.php';
}
$account_status_api = ASSET_BASE . '/app/api/admin/account_status.php';
require __DIR__ . '/partials/account_status_modal.php';
?>

<style>
.admin-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(7, 20, 40, 0.6);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 20px;
    pointer-events: none;
}
.admin-modal-overlay[style*="flex"] {
    pointer-events: auto;
}
.admin-modal-dialog {
    background: #fff;
    width: min(520px, 100%);
    padding: 28px 32px;
    border-radius: var(--mc-r-xl, 16px);
    box-shadow: var(--mc-shadow-lg, 0 20px 50px rgba(0,0,0,.15));
}
.admin-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 24px;
}
.admin-modal-close {
    background: none;
    border: none;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    color: var(--mc-text-faint, #94a3b8);
}
.admin-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.admin-form-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--mc-text-muted);
    text-transform: uppercase;
    margin-bottom: 8px;
}
.admin-form-input {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--mc-border, #e2e8f0);
    border-radius: var(--mc-r-md, 10px);
    font-size: 14px;
    box-sizing: border-box;
}
.form-group { margin-bottom: 16px; }
.admin-form-error {
    color: #dc2626;
    font-size: 13px;
    margin-bottom: 12px;
}
.admin-modal-actions {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 8px;
}
@media (max-width: 560px) {
    .admin-form-grid { grid-template-columns: 1fr; }
}
</style>

<script>
(function () {
    document.querySelectorAll('.js-verify-doctor').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (typeof openDoctorVerifyModal === 'function') {
                openDoctorVerifyModal(btn.dataset.userId, btn.dataset.name || '', btn.dataset.prc || '');
            }
        });
    });

    document.querySelectorAll('.js-reject-doctor').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (typeof openDoctorRejectModal === 'function') {
                openDoctorRejectModal(btn.dataset.userId, btn.dataset.name || '');
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
