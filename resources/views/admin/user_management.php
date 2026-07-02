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
require_once BASE_PATH . '/app/includes/provider_verification.php';
require_once BASE_PATH . '/app/includes/user_account_status.php';
require_once BASE_PATH . '/app/includes/profile_picture.php';
require_once BASE_PATH . '/app/includes/portal_paths.php';

provider_verification_ensure_schema($pdo);
user_account_status_ensure_schema($pdo);
profile_picture_ensure_schema($pdo);

require_once __DIR__ . '/_portal_access.php';

$page_title = 'User Account Management';
$show_success = isset($_GET['created']);
$show_restored = isset($_GET['restored']);
$is_superadmin = portal_is_superadmin();

$status_filter = $_GET['status'] ?? 'all';
$role_filter   = $_GET['role'] ?? 'all';
if (portal_is_superadmin_shell() && $role_filter === 'admin') {
    header('Location: ' . portal_views_base() . '/administrators.php');
    exit;
}
$search        = trim($_GET['search'] ?? '');
$sort          = $_GET['sort'] ?? 'archived_at';
$order         = $_GET['order'] ?? 'desc';

$is_archived_view = ($status_filter === 'archived');

$users = user_account_status_fetch_users($pdo, [
    'status' => $status_filter,
    'role'   => $role_filter,
    'search' => $search,
    'sort'   => $sort,
    'order'  => $order,
]);

$archived_count = 0;
if (!$is_archived_view) {
    $archived_count = count(user_account_status_fetch_users($pdo, ['status' => 'archived', 'role' => $role_filter, 'search' => $search]));
}

function um_sort_url(string $col, string $currentSort, string $currentOrder, array $params): string
{
    $newOrder = ($currentSort === $col && $currentOrder === 'desc') ? 'asc' : 'desc';
    $params['sort'] = $col;
    $params['order'] = $newOrder;
    return '?' . http_build_query($params);
}

$filter_params = array_filter([
    'status' => $status_filter !== 'all' ? $status_filter : null,
    'role'   => $role_filter !== 'all' ? $role_filter : null,
    'search' => $search !== '' ? $search : null,
    'sort'   => $is_archived_view && $sort !== 'archived_at' ? $sort : null,
    'order'  => $is_archived_view && $order !== 'desc' ? $order : null,
], static fn($v) => $v !== null && $v !== '');

require_once __DIR__ . '/partials/layout_open.php';
?>

<?php if ($show_success): ?>
<div class="mc-card" style="margin-bottom: 20px; border-left: 4px solid #16a34a; background: #f0fdf4;">
    <strong style="color: #15803d;">Doctor account created.</strong>
    <span class="text-muted" style="font-size: 13px;"> PRC license was manually verified and the account is <strong>active</strong>.</span>
</div>
<?php endif; ?>

<?php if ($show_restored): ?>
<div class="mc-card" style="margin-bottom: 20px; border-left: 4px solid #16a34a; background: #f0fdf4;">
    <strong style="color: #15803d;">Account restored successfully.</strong>
    <span class="text-muted" style="font-size: 13px;"> The user has been notified and can sign in again.</span>
</div>
<?php endif; ?>

<?php if (!$is_superadmin): ?>
<div class="mc-card" style="margin-bottom: 20px; border-left: 4px solid #018a93; background: #f0fdfa;">
    <strong style="color: #0f766e;">Limited account status permissions.</strong>
    <span class="text-muted" style="font-size: 13px;"> You may <strong>archive</strong> and <strong>view archived</strong> accounts. Only the Super Administrator can restore archived accounts.</span>
</div>
<?php endif; ?>

<div class="header-row" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; flex-wrap: wrap; gap: 16px;">
    <div>
        <h2 class="text-h2">User Account Management</h2>
        <p class="text-muted">Monitor and manage all system accounts including patients and medical staff.</p>
    </div>
    <?php if (!$is_archived_view): ?>
    <button type="button" class="mc-btn mc-btn--primary" data-open-create-doctor>
        Create Doctor Account
    </button>
    <?php endif; ?>
</div>

<form method="GET" class="mc-card" style="padding: 16px 20px; margin-bottom: 20px;">
    <div class="um-filter-bar">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by name or email..." class="mc-btn mc-btn--outline um-filter-bar" style="flex:1; min-width:200px;">
        <select name="status" class="mc-btn mc-btn--outline" onchange="this.form.submit()">
            <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Accounts</option>
            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending Approval</option>
            <option value="suspended" <?= $status_filter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            <option value="archived" <?= $status_filter === 'archived' ? 'selected' : '' ?>>Archived</option>
        </select>
        <select name="role" class="mc-btn mc-btn--outline" onchange="this.form.submit()">
            <option value="all" <?= $role_filter === 'all' ? 'selected' : '' ?>>All Roles</option>
            <option value="patient" <?= $role_filter === 'patient' ? 'selected' : '' ?>>Patients</option>
            <option value="provider" <?= $role_filter === 'provider' ? 'selected' : '' ?>>Doctors</option>
            <option value="bhw" <?= $role_filter === 'bhw' ? 'selected' : '' ?>>Barangay Health Workers</option>
            <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Administrators</option>
        </select>
        <button type="submit" class="mc-btn mc-btn--outline">Search</button>
        <?php if (!empty($filter_params)): ?>
        <a href="?" class="mc-btn mc-btn--outline" style="color:#64748b;">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($is_archived_view): ?>
<div class="um-archived-badge">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
    Archived Accounts (<?= count($users) ?>)
</div>
<?php endif; ?>

<div class="mc-card" style="padding: 0; overflow: hidden;">
    <table class="mc-table">
        <thead>
            <tr>
                <?php if ($is_archived_view): ?>
                <th>
                    <a href="<?= um_sort_url('name', $sort, $order, array_merge($filter_params, ['status' => 'archived'])) ?>" class="um-sort-link <?= $sort === 'name' ? 'is-active' : '' ?>">Name & ID</a>
                </th>
                <th>Email</th>
                <th>
                    <a href="<?= um_sort_url('role', $sort, $order, array_merge($filter_params, ['status' => 'archived'])) ?>" class="um-sort-link <?= $sort === 'role' ? 'is-active' : '' ?>">Role</a>
                </th>
                <th>Status</th>
                <th>
                    <a href="<?= um_sort_url('archived_at', $sort, $order, array_merge($filter_params, ['status' => 'archived'])) ?>" class="um-sort-link <?= $sort === 'archived_at' ? 'is-active' : '' ?>">Date Archived</a>
                </th>
                <th>Time</th>
                <th>
                    <a href="<?= um_sort_url('archived_by', $sort, $order, array_merge($filter_params, ['status' => 'archived'])) ?>" class="um-sort-link <?= $sort === 'archived_by' ? 'is-active' : '' ?>">Archived By</a>
                </th>
                <th>Archive Reason</th>
                <th>Actions</th>
                <?php else: ?>
                <th>Name & ID</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>PRC</th>
                <th>Joined</th>
                <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u):
                $effective_status = user_account_status_effective($u);
                $badge = AccountStatus::badge($effective_status);
                $full_name = htmlspecialchars($u['first_name'] . ' ' . $u['last_name'], ENT_QUOTES);
                $is_provider = ($u['role'] === 'provider');
                $allowed_actions = user_account_status_allowed_actions_for_role(
                    $effective_status,
                    $is_superadmin,
                    (string) ($u['role'] ?? '')
                );
                $initials = profile_picture_initials($u['first_name'] ?? '', $u['last_name'] ?? '');
                $picture_url = profile_picture_public_url($u['profile_picture'] ?? null);
                $archiver_name = trim(($u['archiver_first_name'] ?? '') . ' ' . ($u['archiver_last_name'] ?? ''));
            ?>
            <tr>
                <td>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <?php if ($picture_url): ?>
                            <?= profile_picture_render($initials, $picture_url, '', 'sm') ?>
                        <?php else: ?>
                        <div style="width: 32px; height: 32px; background: var(--mc-ice-blue); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; color: var(--mc-aqua-medium); border: 1px solid var(--mc-border-thin);">
                            <?= $initials ?>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight: 700; color: var(--mc-navy-dark);"><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></div>
                            <div class="text-xs text-muted">#USR-<?= str_pad((string) $u['id'], 5, '0', STR_PAD_LEFT) ?></div>
                        </div>
                    </div>
                </td>
                <td><span class="text-sm"><?= htmlspecialchars($u['email']) ?></span></td>
                <td><span class="mc-badge"><?= htmlspecialchars(user_account_role_label((string) $u['role'])) ?></span></td>
                <td>
                    <span class="mc-badge" style="background: <?= $badge['bg'] ?>; color: <?= $badge['color'] ?>;">
                        <?= htmlspecialchars($badge['label']) ?>
                    </span>
                </td>

                <?php if ($is_archived_view):
                    $archived_ts = $u['archived_at'] ? strtotime((string) $u['archived_at']) : null;
                ?>
                <td><span class="text-xs text-muted"><?= $archived_ts ? date('M j, Y', $archived_ts) : '—' ?></span></td>
                <td><span class="text-xs text-muted"><?= $archived_ts ? date('g:i A', $archived_ts) : '—' ?></span></td>
                <td><span class="text-xs"><?= $archiver_name !== '' ? htmlspecialchars($archiver_name) : '—' ?></span></td>
                <td><span class="text-xs" style="max-width:200px;display:block;"><?= !empty($u['archive_reason']) ? htmlspecialchars((string) $u['archive_reason']) : '—' ?></span></td>
                <td>
                    <div class="mc-status-actions">
                        <?php if ($is_superadmin): ?>
                        <button type="button" class="mc-btn mc-btn--primary js-archived-restore" style="padding:6px 10px;font-size:11px;" data-user-id="<?= (int) $u['id'] ?>" data-user-name="<?= $full_name ?>">Restore</button>
                        <?php else: ?>
                        <span class="um-restore-locked" title="Only the Super Administrator can restore archived accounts.">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Restore (Super Admin Only)
                        </span>
                        <?php endif; ?>
                        <button type="button" class="mc-btn mc-btn--outline js-archived-details" style="padding:6px 10px;font-size:11px;" data-user-id="<?= (int) $u['id'] ?>" data-user-name="<?= $full_name ?>">View Details</button>
                        <button type="button" class="mc-btn mc-btn--outline js-archived-audit" style="padding:6px 10px;font-size:11px;" data-user-id="<?= (int) $u['id'] ?>" data-user-name="<?= $full_name ?>">View Audit Log</button>
                    </div>
                </td>
                <?php else: ?>
                <td>
                    <?php if ($is_provider): ?>
                        <span class="text-xs"><?= $u['prc_license_number'] ? htmlspecialchars($u['prc_license_number']) : '—' ?></span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="text-xs text-muted"><?= date('M j, Y', strtotime($u['created_at'])) ?></span></td>
                <td>
                    <?php if (!empty($allowed_actions)): ?>
                    <div class="mc-status-actions">
                        <?php foreach ($allowed_actions as $act):
                            $btnClass = in_array($act, ['deactivate', 'suspend', 'reject', 'archive'], true) ? 'mc-btn mc-btn--outline' : 'mc-btn mc-btn--primary';
                            $btnStyle = in_array($act, ['deactivate', 'suspend', 'reject', 'archive'], true) ? 'color:#b91c1c;border-color:#fecaca;' : '';
                        ?>
                        <button type="button" class="<?= $btnClass ?> js-account-status-action" style="padding:6px 10px;font-size:11px;cursor:pointer;<?= $btnStyle ?>"
                                data-user-id="<?= (int) $u['id'] ?>" data-user-name="<?= $full_name ?>" data-action="<?= htmlspecialchars($act) ?>">
                            <?= htmlspecialchars(user_account_status_action_label($act)) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif ($is_provider && ($u['verification_status'] ?? '') === 'pending'): ?>
                    <span class="mc-status-restricted">Only the Super Administrator can perform this action.</span>
                    <?php elseif (!$is_superadmin): ?>
                    <span class="mc-status-restricted">Only the Super Administrator can perform this action.</span>
                    <?php else: ?>
                    <span class="text-muted" style="font-size:12px;">No actions</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>

            <?php if (empty($users)): ?>
            <tr>
                <td colspan="<?= $is_archived_view ? 9 : 7 ?>">
                    <div class="mc-table-empty">
                        <?= $is_archived_view ? 'No archived accounts found.' : 'No users matching your criteria.' ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!$is_archived_view && $archived_count > 0): ?>
<p class="text-muted text-sm" style="margin-top:12px;">
    <a href="?status=archived<?= $role_filter !== 'all' ? '&role=' . urlencode($role_filter) : '' ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" style="color:var(--mc-aqua-medium,#018a93);font-weight:600;">
        View <?= $archived_count ?> archived account<?= $archived_count === 1 ? '' : 's' ?> →
    </a>
</p>
<?php endif; ?>

<?php require __DIR__ . '/partials/create_doctor_modal.php'; ?>

<?php
$account_status_api = ASSET_BASE . '/app/api/admin/account_status.php';
require __DIR__ . '/partials/account_status_modal.php';
require __DIR__ . '/partials/archived_accounts_modals.php';
?>

<style>
.admin-modal-overlay {
    position: fixed; inset: 0; background: rgba(7, 20, 40, 0.6); backdrop-filter: blur(4px);
    align-items: center; justify-content: center; z-index: 1000; padding: 20px;
}
.admin-modal-dialog {
    background: #fff; width: min(680px, 100%); padding: 28px 32px;
    border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,.15);
}
@media (max-width: 560px) { .um-filter-bar { flex-direction: column; align-items: stretch; } }
</style>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
