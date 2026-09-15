<?php
/**
 * Doctor / BHW account list panel for Management hub tabs.
 *
 * Expects: $hub_kind ('doctor'|'bhw'), $hub_tab ('all'|'active'|'rejected'|'archived'|…)
 *
 * Doctor account tabs filter by users.account_status only.
 * PRC verification (provider_profiles.verification_status) stays a separate sub-filter
 * and must not drive which account-status tab a doctor appears on.
 */
declare(strict_types=1);

require_once BASE_PATH . '/app/includes/provider_verification.php';
require_once BASE_PATH . '/app/includes/user_account_status.php';

provider_verification_ensure_schema($pdo);
user_account_status_ensure_schema($pdo);

$hub_kind = $hub_kind ?? 'doctor';
$hub_tab = $hub_tab ?? 'active';
$hub_role = $hub_kind === 'doctor' ? 'provider' : 'bhw';
$is_superadmin = portal_is_superadmin();
$verify_filter = $_GET['verify'] ?? 'all';
$allowed_verify = ['all', 'verified', 'pending', 'rejected'];
if (!in_array($verify_filter, $allowed_verify, true)) {
    $verify_filter = 'all';
}
$search = trim((string) ($_GET['search'] ?? ''));

/**
 * Map hub tab → account_status filter.
 * null means no status WHERE (All Doctors / All).
 */
$doctor_tab_status = [
    'all'      => null,
    'active'   => AccountStatus::ACTIVE,
    'rejected' => AccountStatus::REJECTED,
    'archived' => AccountStatus::ARCHIVED,
    'pending'  => AccountStatus::PENDING_APPROVAL,
];

$query = "
    SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.role, u.is_active, u.account_status, u.created_at,
           u.archived_at, u.archived_by, u.archive_reason,
           pp.prc_license_number, pp.verification_status, pp.rejection_note, pp.verified_at,
           ab.first_name AS archiver_first_name, ab.last_name AS archiver_last_name
    FROM users u
    LEFT JOIN provider_profiles pp ON pp.user_id = u.id
    LEFT JOIN users ab ON ab.id = u.archived_by
    WHERE u.role = ?
";
$params = [$hub_role];

if ($hub_kind === 'doctor') {
    $statusFilter = array_key_exists($hub_tab, $doctor_tab_status)
        ? $doctor_tab_status[$hub_tab]
        : AccountStatus::ACTIVE;

    // All Doctors: no account-status filter. Other tabs: exact account_status match.
    if ($statusFilter !== null) {
        if ($statusFilter === AccountStatus::PENDING_APPROVAL) {
            $query .= " AND u.account_status IN ('pending_approval', 'pending')";
        } else {
            $query .= ' AND u.account_status = ?';
            $params[] = $statusFilter;
        }
    }

    // PRC sub-filter is independent of account status (available on All + Active).
    if ($verify_filter !== 'all' && in_array($hub_tab, ['all', 'active'], true)) {
        $query .= ' AND pp.verification_status = ?';
        $params[] = $verify_filter;
    }
} else {
    // BHW: preserve prior behavior (active excludes archived; archived tab only archived).
    if ($hub_tab === 'archived') {
        $query .= " AND u.account_status = 'archived'";
    } elseif ($hub_tab === 'active') {
        $query .= " AND u.account_status = 'active'";
    } elseif ($hub_tab === 'rejected') {
        $query .= " AND u.account_status = 'rejected'";
    } else {
        $query .= " AND u.account_status != 'archived'";
    }
}

if ($search !== '') {
    $query .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?';
    if ($hub_kind === 'doctor') {
        $query .= ' OR pp.prc_license_number LIKE ?';
    }
    $query .= ')';
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s, $s);
    if ($hub_kind === 'doctor') {
        $params[] = $s;
    }
}

if ($hub_tab === 'archived') {
    $query .= ' ORDER BY u.archived_at DESC, u.created_at DESC';
} else {
    $query .= ' ORDER BY u.created_at DESC';
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

$role_label = $hub_kind === 'doctor' ? 'doctor' : 'BHW';
$kind_title = $hub_kind === 'doctor' ? 'Doctor' : 'BHW';
$empty_messages = [
    'all'      => 'No ' . $role_label . ' accounts found.',
    'active'   => 'No active ' . $role_label . ' accounts found.',
    'rejected' => 'No rejected ' . $role_label . ' accounts found.',
    'archived' => 'No archived ' . $role_label . ' accounts found.',
    'pending'  => 'No pending ' . $role_label . ' accounts found.',
];
$empty_message = $empty_messages[$hub_tab] ?? 'No accounts found.';

$panel_titles = [
    'all'      => 'All ' . $kind_title . ' Accounts',
    'active'   => 'Active ' . $kind_title . ' Accounts',
    'rejected' => 'Rejected ' . $kind_title . ' Accounts',
    'archived' => 'Archived ' . $kind_title . ' Accounts',
    'pending'  => 'Pending ' . $kind_title . ' Accounts',
];
$panel_title = $panel_titles[$hub_tab] ?? ($kind_title . ' Accounts');

$show_prc_subfilters = ($hub_kind === 'doctor' && in_array($hub_tab, ['all', 'active'], true));
$show_archived_col = ($hub_tab === 'archived');
$col_count = $hub_kind === 'doctor'
    ? ($show_archived_col ? 7 : 6)
    : ($show_archived_col ? 5 : 4);

if (!isset($hub_views_base)) {
    require_once BASE_PATH . '/app/includes/portal_paths.php';
    $hub_views_base = portal_views_base();
}
$hub_base = $hub_base ?? ($hub_kind === 'doctor' ? 'doctor_applications.php' : 'bhw_applications.php');
$tab_query = $hub_tab === 'all' ? '' : ('?tab=' . urlencode($hub_tab));
$base_tab_url = $hub_views_base . '/' . $hub_base . $tab_query;
?>

<div class="staff-apps-card staff-mgmt-accounts-panel">
    <div class="staff-mgmt-accounts-panel__head">
        <h2 class="staff-mgmt-accounts-panel__title">
            <?= htmlspecialchars($panel_title) ?>
        </h2>
        <p class="staff-mgmt-accounts-panel__desc">
            <?php if ($hub_tab === 'all'): ?>
                Every doctor account in the system, regardless of account status. PRC verification is listed separately.
            <?php elseif ($hub_tab === 'rejected'): ?>
                Doctor accounts with rejected account status. PRC verification remains a separate field.
            <?php elseif ($hub_tab === 'archived'): ?>
                Archived accounts. Restore is available to Super Administrators only.
            <?php else: ?>
                Approved accounts and account status actions. New registrations go through the application workflow.
            <?php endif; ?>
        </p>
    </div>

    <?php if ($show_prc_subfilters): ?>
    <div class="staff-mgmt-subfilters">
        <?php
        foreach (['all' => 'All PRC Status', 'verified' => 'Verified', 'pending' => 'Pending', 'rejected' => 'Rejected'] as $vf => $vfLabel):
            $sep = str_contains($base_tab_url, '?') ? '&' : '?';
            $href = $base_tab_url . ($vf !== 'all' ? $sep . 'verify=' . urlencode($vf) : '');
        ?>
        <a href="<?= htmlspecialchars($href) ?>"
           class="mc-btn mc-btn--sm <?= $verify_filter === $vf ? 'mc-btn--primary' : 'mc-btn--outline' ?>">
            <?= htmlspecialchars($vfLabel) ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="staff-apps-table-wrap">
        <table class="staff-apps-table staff-mgmt-accounts-table admin-stack-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <?php if ($hub_kind === 'doctor'): ?>
                    <th>PRC License</th>
                    <th>Verification</th>
                    <?php endif; ?>
                    <th>Account</th>
                    <?php if ($show_archived_col): ?>
                    <th>Archived</th>
                    <?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $s):
                    // Account column / actions use stored account_status (not PRC).
                    $storedStatus = AccountStatus::normalize((string) ($s['account_status'] ?? AccountStatus::ACTIVE));
                    if ($storedStatus === AccountStatus::ACTIVE && empty($s['is_active'])) {
                        $storedStatus = AccountStatus::DEACTIVATED;
                    }
                    $acctBadge = AccountStatus::badge($storedStatus);
                    $staff_name = htmlspecialchars($s['first_name'] . ' ' . $s['last_name'], ENT_QUOTES);
                    $staff_actions = user_account_status_allowed_actions_for_role(
                        $storedStatus,
                        $is_superadmin,
                        (string) ($s['role'] ?? '')
                    );
                    $show_prc_actions = $hub_kind === 'doctor'
                        && !empty($s['prc_license_number'])
                        && $is_superadmin
                        && in_array($hub_tab, ['all', 'active'], true);
                ?>
                <tr>
                    <td data-label="Name">
                        <strong><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></strong>
                        <div class="staff-apps-meta staff-apps-meta--muted"><?= $hub_kind === 'doctor' ? 'Doctor' : 'BHW' ?></div>
                    </td>
                    <td data-label="Email"><span class="staff-apps-meta"><?= htmlspecialchars($s['email']) ?></span></td>
                    <?php if ($hub_kind === 'doctor'): ?>
                    <td data-label="PRC License">
                        <span class="staff-apps-meta"><?= !empty($s['prc_license_number']) ? htmlspecialchars($s['prc_license_number']) : '—' ?></span>
                    </td>
                    <td data-label="Verification">
                        <?php if (!empty($s['verification_status'])):
                            $v = $s['verification_status'];
                            $vStyles = match ($v) {
                                'verified' => ['mc-badge--approved', 'Verified'],
                                'rejected' => ['mc-badge--danger', 'Rejected'],
                                default => ['mc-badge--pending', 'Pending'],
                            };
                        ?>
                        <span class="mc-badge <?= $vStyles[0] ?>"><?= $vStyles[1] ?></span>
                        <?php else: ?>
                        <span class="staff-apps-meta staff-apps-meta--muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td data-label="Account">
                        <span class="mc-badge <?= htmlspecialchars($acctBadge['class'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($acctBadge['label']) ?>
                        </span>
                    </td>
                    <?php if ($show_archived_col): ?>
                    <td data-label="Archived">
                        <span class="staff-apps-meta staff-apps-meta--muted">
                            <?= !empty($s['archived_at']) ? date('M j, Y', strtotime((string) $s['archived_at'])) : '—' ?>
                        </span>
                    </td>
                    <?php endif; ?>
                    <td data-label="Actions" class="staff-apps-td--actions">
                        <?php if ($show_prc_actions): ?>
                        <div class="staff-mgmt-actions">
                            <?php if (($s['verification_status'] ?? '') !== 'verified'): ?>
                            <button type="button" class="mc-btn mc-btn--success mc-btn--sm js-verify-doctor"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-name="<?= $staff_name ?>"
                                    data-prc="<?= htmlspecialchars($s['prc_license_number'], ENT_QUOTES) ?>">Verify</button>
                            <?php endif; ?>
                            <?php if (($s['verification_status'] ?? '') !== 'rejected'): ?>
                            <button type="button" class="mc-btn mc-btn--outline mc-btn--danger mc-btn--sm js-reject-doctor"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-name="<?= $staff_name ?>">Reject</button>
                            <?php endif; ?>
                            <?php foreach ($staff_actions as $act): ?>
                            <button type="button" class="mc-btn mc-btn--outline mc-btn--sm js-account-status-action"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-user-name="<?= $staff_name ?>"
                                    data-action="<?= htmlspecialchars($act) ?>">
                                <?= htmlspecialchars(user_account_status_action_label($act)) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif (!empty($staff_actions)): ?>
                        <div class="staff-mgmt-actions">
                            <?php foreach ($staff_actions as $act): ?>
                            <button type="button" class="mc-btn mc-btn--outline mc-btn--sm js-account-status-action"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-user-name="<?= $staff_name ?>"
                                    data-action="<?= htmlspecialchars($act) ?>">
                                <?= htmlspecialchars(user_account_status_action_label($act)) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <?php elseif ($hub_kind === 'doctor' && !$is_superadmin): ?>
                        <span class="staff-apps-meta staff-apps-meta--muted">Super Admin only</span>
                        <?php else: ?>
                        <span class="staff-apps-meta staff-apps-meta--muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($staff)): ?>
                <tr>
                    <td colspan="<?= (int) $col_count ?>">
                        <div class="staff-apps-empty">
                            <p class="staff-apps-empty__title"><?= htmlspecialchars($empty_message) ?></p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
