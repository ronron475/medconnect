<?php
/**
 * Active / rejected / archived staff accounts panel for Doctor & BHW Management hubs.
 *
 * Expects: $hub_kind ('doctor'|'bhw'), $hub_tab ('active'|'archived')
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

if ($hub_tab === 'archived') {
    $staff = user_account_status_fetch_users($pdo, [
        'status' => 'archived',
        'role'   => $hub_role,
        'search' => trim($_GET['search'] ?? ''),
    ]);
} else {
    $query = "
        SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.role, u.is_active, u.account_status, u.created_at,
               pp.prc_license_number, pp.verification_status, pp.rejection_note, pp.verified_at
        FROM users u
        LEFT JOIN provider_profiles pp ON pp.user_id = u.id
        WHERE u.role = ?
          AND u.account_status != 'archived'
    ";
    $params = [$hub_role];

    if ($hub_kind === 'doctor') {
        if ($hub_tab === 'active') {
            $query .= " AND u.account_status IN ('active', 'pending')";
        }
        if ($verify_filter !== 'all' && $hub_tab === 'active') {
            $query .= ' AND pp.verification_status = ?';
            $params[] = $verify_filter;
        }
    } else {
        if ($hub_tab === 'active') {
            $query .= " AND u.account_status = 'active'";
        }
    }

    $query .= ' ORDER BY u.created_at DESC';
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$role_label = $hub_kind === 'doctor' ? 'doctor' : 'BHW';
$empty_messages = [
    'active'   => 'No active ' . $role_label . ' accounts found.',
    'archived' => 'No archived ' . $role_label . ' accounts found.',
];
$empty_message = $empty_messages[$hub_tab] ?? 'No accounts found.';
?>

<div class="staff-apps-card staff-mgmt-accounts-panel">
    <div class="staff-mgmt-accounts-panel__head">
        <h2 class="staff-mgmt-accounts-panel__title">
            <?php if ($hub_tab === 'archived'): ?>
                Archived <?= $hub_kind === 'doctor' ? 'Doctor' : 'BHW' ?> Accounts
            <?php else: ?>
                Active <?= $hub_kind === 'doctor' ? 'Doctor' : 'BHW' ?> Accounts
            <?php endif; ?>
        </h2>
        <p class="staff-mgmt-accounts-panel__desc">
            Approved accounts and account status actions. New registrations go through the application workflow.
        </p>
    </div>

    <?php if ($hub_kind === 'doctor' && $hub_tab === 'active'): ?>
    <div class="staff-mgmt-subfilters">
        <?php
        $baseTab = $hub_views_base . '/' . ($hub_base ?? 'doctor_applications.php') . '?tab=active';
        foreach (['all' => 'All PRC Status', 'verified' => 'Verified', 'pending' => 'Pending', 'rejected' => 'Rejected'] as $vf => $vfLabel):
        ?>
        <a href="<?= htmlspecialchars($baseTab . ($vf !== 'all' ? '&verify=' . urlencode($vf) : '')) ?>"
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
                    <?php if ($hub_tab === 'archived'): ?>
                    <th>Archived</th>
                    <?php endif; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $s):
                    $effective = user_account_status_effective($s);
                    $acctBadge = AccountStatus::badge($effective);
                    $staff_name = htmlspecialchars($s['first_name'] . ' ' . $s['last_name'], ENT_QUOTES);
                    $staff_actions = user_account_status_allowed_actions_for_role(
                        $effective,
                        $is_superadmin,
                        (string) ($s['role'] ?? '')
                    );
                    $archiver_name = trim(($s['archiver_first_name'] ?? '') . ' ' . ($s['archiver_last_name'] ?? ''));
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
                    <?php if ($hub_tab === 'archived'): ?>
                    <td data-label="Archived">
                        <span class="staff-apps-meta staff-apps-meta--muted">
                            <?= !empty($s['archived_at']) ? date('M j, Y', strtotime((string) $s['archived_at'])) : '—' ?>
                        </span>
                    </td>
                    <?php endif; ?>
                    <td data-label="Actions" class="staff-apps-td--actions">
                        <?php if ($hub_kind === 'doctor' && !empty($s['prc_license_number']) && $is_superadmin && $hub_tab === 'active'): ?>
                        <div class="staff-mgmt-actions">
                            <?php if (($s['verification_status'] ?? '') !== 'verified'): ?>
                            <button type="button" class="mc-btn mc-btn--primary mc-btn--sm js-verify-doctor"
                                    data-user-id="<?= (int) $s['id'] ?>"
                                    data-name="<?= $staff_name ?>"
                                    data-prc="<?= htmlspecialchars($s['prc_license_number'], ENT_QUOTES) ?>">Verify</button>
                            <?php endif; ?>
                            <?php if (($s['verification_status'] ?? '') !== 'rejected'): ?>
                            <button type="button" class="mc-btn mc-btn--outline mc-btn--sm js-reject-doctor"
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
                    <td colspan="<?= $hub_kind === 'doctor' ? ($hub_tab === 'archived' ? 7 : 6) : ($hub_tab === 'archived' ? 5 : 4) ?>">
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
