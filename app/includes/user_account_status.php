<?php
declare(strict_types=1);

/**
 * User account status management — RBAC-enforced lifecycle states.
 *
 * Only Super Administrators may change account status (enforced in API layer).
 * Status changes sync users.is_active for existing authentication checks.
 */

final class AccountStatus
{
    public const PENDING_APPROVAL = 'pending_approval';
    public const ACTIVE           = 'active';
    public const SUSPENDED        = 'suspended';
    public const DEACTIVATED      = 'deactivated';
    public const REJECTED         = 'rejected';
    public const ARCHIVED         = 'archived';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::PENDING_APPROVAL,
            self::ACTIVE,
            self::SUSPENDED,
            self::DEACTIVATED,
            self::REJECTED,
            self::ARCHIVED,
        ];
    }

    public static function label(string $status): string
    {
        return match (self::normalize($status)) {
            self::PENDING_APPROVAL => 'Pending Approval',
            self::ACTIVE           => 'Active',
            self::SUSPENDED        => 'Suspended',
            self::DEACTIVATED      => 'Deactivated',
            self::REJECTED         => 'Rejected',
            self::ARCHIVED         => 'Archived',
            default                => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function normalize(string $status): string
    {
        $status = strtolower(trim(str_replace('-', '_', $status)));
        if ($status === 'pending') {
            return self::PENDING_APPROVAL;
        }
        if ($status === 'inactive') {
            return self::DEACTIVATED;
        }
        return in_array($status, self::all(), true) ? $status : self::DEACTIVATED;
    }

    /** @return array{bg: string, color: string, label: string} */
    public static function badge(string $status): array
    {
        return match (self::normalize($status)) {
            self::ACTIVE           => ['bg' => '#dcfce7', 'color' => '#16a34a', 'label' => 'Active'],
            self::PENDING_APPROVAL => ['bg' => '#fef3c7', 'color' => '#b45309', 'label' => 'Pending Approval'],
            self::SUSPENDED        => ['bg' => '#ffedd5', 'color' => '#c2410c', 'label' => 'Suspended'],
            self::DEACTIVATED      => ['bg' => '#fee2e2', 'color' => '#991b1b', 'label' => 'Deactivated'],
            self::REJECTED         => ['bg' => '#fee2e2', 'color' => '#b91c1c', 'label' => 'Rejected'],
            self::ARCHIVED         => ['bg' => '#f1f5f9', 'color' => '#475569', 'label' => 'Archived'],
            default                => ['bg' => '#f1f5f9', 'color' => '#64748b', 'label' => self::label($status)],
        };
    }

    public static function isLoginAllowed(string $status): bool
    {
        return self::normalize($status) === self::ACTIVE;
    }
}

function user_account_status_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    require_once __DIR__ . '/patient_account_security.php';
    patient_security_ensure_schema($pdo);

    $cols = patient_security_user_columns($pdo);
    if (!in_array('account_status', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN account_status VARCHAR(30) NOT NULL DEFAULT 'active'");
        $cols[] = 'account_status';
    } else {
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN account_status VARCHAR(30) NOT NULL DEFAULT 'active'");
        } catch (PDOException $e) {
            // Column may already be wide enough.
        }
    }

    $archiveCols = [
        'archived_at'    => 'DATETIME NULL DEFAULT NULL',
        'archived_by'    => 'INT UNSIGNED NULL DEFAULT NULL',
        'archive_reason' => 'TEXT NULL',
        'restored_at'    => 'DATETIME NULL DEFAULT NULL',
        'restored_by'    => 'INT UNSIGNED NULL DEFAULT NULL',
        'restore_reason' => 'TEXT NULL',
    ];
    foreach ($archiveCols as $col => $def) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$col} {$def}");
            $cols[] = $col;
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_account_status_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            target_user_id INT UNSIGNED NOT NULL,
            target_user_name VARCHAR(200) NOT NULL,
            target_user_role VARCHAR(20) NOT NULL,
            previous_status VARCHAR(30) NOT NULL,
            new_status VARCHAR(30) NOT NULL,
            action_performed VARCHAR(30) NOT NULL,
            reason TEXT NOT NULL,
            performed_by INT UNSIGNED NOT NULL,
            performed_by_name VARCHAR(200) NOT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_uasl_target (target_user_id, created_at),
            KEY idx_uasl_performer (performed_by, created_at),
            KEY idx_uasl_action (action_performed, created_at),
            CONSTRAINT fk_uasl_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_uasl_performer FOREIGN KEY (performed_by) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    user_account_status_backfill($pdo);
    $done = true;
}

function user_account_status_backfill(PDO $pdo): void
{
    try {
        require_once __DIR__ . '/provider_verification.php';
        provider_verification_ensure_schema($pdo);

        $pdo->exec("
            UPDATE users
            SET account_status = 'active'
            WHERE is_active = 1
              AND (account_status IS NULL OR account_status = '' OR account_status = 'active')
        ");

        $pdo->exec("
            UPDATE users u
            INNER JOIN provider_profiles pp ON pp.user_id = u.id
            SET u.account_status = 'pending_approval'
            WHERE u.role = 'provider'
              AND pp.verification_status = 'pending'
        ");

        $pdo->exec("
            UPDATE users u
            INNER JOIN provider_profiles pp ON pp.user_id = u.id
            SET u.account_status = 'rejected'
            WHERE u.role = 'provider'
              AND pp.verification_status = 'rejected'
        ");

        $pdo->exec("
            UPDATE users
            SET account_status = 'deactivated'
            WHERE is_active = 0
              AND account_status IN ('', 'active')
        ");

        $pdo->exec("
            UPDATE users u
            INNER JOIN (
                SELECT l1.target_user_id, l1.created_at, l1.performed_by, l1.reason
                FROM user_account_status_logs l1
                INNER JOIN (
                    SELECT target_user_id, MAX(created_at) AS max_at
                    FROM user_account_status_logs
                    WHERE action_performed = 'archive'
                    GROUP BY target_user_id
                ) l2 ON l1.target_user_id = l2.target_user_id AND l1.created_at = l2.max_at
                WHERE l1.action_performed = 'archive'
            ) lg ON lg.target_user_id = u.id
            SET u.archived_at = lg.created_at,
                u.archived_by = lg.performed_by,
                u.archive_reason = lg.reason
            WHERE u.account_status = 'archived'
              AND u.archived_at IS NULL
        ");
    } catch (PDOException $e) {
        // Non-fatal during bootstrap.
    }
}

function user_account_status_sync_is_active(string $status): int
{
    return AccountStatus::isLoginAllowed($status) ? 1 : 0;
}

/**
 * Resolve display status for a user row (may combine provider verification).
 *
 * @param array<string, mixed> $user
 */
function user_account_status_effective(array $user): string
{
    $stored = AccountStatus::normalize((string) ($user['account_status'] ?? AccountStatus::ACTIVE));

    if (($user['role'] ?? '') === 'provider') {
        $verification = (string) ($user['verification_status'] ?? '');
        if ($verification === 'pending') {
            return AccountStatus::PENDING_APPROVAL;
        }
        if ($verification === 'rejected') {
            return AccountStatus::REJECTED;
        }
    }

    if ($stored === AccountStatus::ACTIVE && empty($user['is_active'])) {
        return AccountStatus::DEACTIVATED;
    }

    return $stored;
}

/** @return string[] */
function user_account_status_allowed_actions_for_role(string $currentStatus, bool $isSuperadmin, string $targetRole = ''): array
{
    $status = AccountStatus::normalize($currentStatus);
    $actions = user_account_status_allowed_actions($currentStatus);

    if ($status === AccountStatus::ARCHIVED) {
        return $isSuperadmin ? ['restore'] : [];
    }

    if ($isSuperadmin) {
        return $actions;
    }

    if (in_array($targetRole, ['admin', 'superadmin'], true)) {
        return [];
    }

    return array_values(array_intersect($actions, ['archive']));
}

/** @return string[] */
function user_account_status_allowed_actions(string $currentStatus): array
{
    $status = AccountStatus::normalize($currentStatus);

    return match ($status) {
        AccountStatus::PENDING_APPROVAL => ['approve', 'reject'],
        AccountStatus::ACTIVE           => ['deactivate', 'suspend', 'archive'],
        AccountStatus::SUSPENDED        => ['reactivate', 'archive'],
        AccountStatus::DEACTIVATED      => ['activate', 'archive'],
        AccountStatus::REJECTED         => ['activate', 'archive'],
        AccountStatus::ARCHIVED         => ['restore'],
        default                         => ['activate', 'archive'],
    };
}

function user_account_role_label(string $role): string
{
    return match ($role) {
        'patient'  => 'Patient',
        'provider' => 'Doctor',
        'bhw'      => 'Barangay Health Worker',
        'admin'    => 'Administrator',
        'superadmin' => 'Super Administrator',
        default    => ucfirst($role),
    };
}

/** Map UI role filter values to DB role. */
function user_account_role_filter_to_db(string $filter): ?string
{
    return match ($filter) {
        'patient', 'provider', 'bhw', 'admin' => $filter,
        default => null,
    };
}

function user_account_status_action_to_status(string $action, string $currentStatus): ?string
{
    $action = strtolower(trim($action));
    $current = AccountStatus::normalize($currentStatus);

    return match ($action) {
        'approve'    => AccountStatus::ACTIVE,
        'activate'   => AccountStatus::ACTIVE,
        'reactivate' => AccountStatus::ACTIVE,
        'deactivate' => AccountStatus::DEACTIVATED,
        'suspend'    => AccountStatus::SUSPENDED,
        'reject'     => AccountStatus::REJECTED,
        'archive'    => AccountStatus::ARCHIVED,
        'restore'    => AccountStatus::ACTIVE,
        default      => null,
    };
}

function user_account_status_action_label(string $action): string
{
    return match (strtolower(trim($action))) {
        'approve'    => 'Approve',
        'activate'   => 'Activate',
        'reactivate' => 'Reactivate',
        'deactivate' => 'Deactivate',
        'suspend'    => 'Suspend',
        'reject'     => 'Reject',
        'archive'    => 'Archive',
        'restore'    => 'Restore',
        default      => ucfirst($action),
    };
}

function user_account_status_matches_filter(string $effectiveStatus, string $statusFilter): bool
{
    $effective = AccountStatus::normalize($effectiveStatus);
    $filter = strtolower(trim($statusFilter));

    if ($filter === 'all') {
        return $effective !== AccountStatus::ARCHIVED;
    }

    if ($filter === 'pending') {
        $filter = AccountStatus::PENDING_APPROVAL;
    } else {
        $filter = AccountStatus::normalize($filter);
    }

    return match ($filter) {
        AccountStatus::ACTIVE           => $effective === AccountStatus::ACTIVE,
        AccountStatus::PENDING_APPROVAL => $effective === AccountStatus::PENDING_APPROVAL,
        AccountStatus::SUSPENDED        => $effective === AccountStatus::SUSPENDED,
        AccountStatus::ARCHIVED         => $effective === AccountStatus::ARCHIVED,
        default                         => true,
    };
}

/**
 * Change a user's account status.
 * Super Admin: all actions. Administrator: archive only (enforced here).
 *
 * @return array{success: bool, message: string, previous_status?: string, new_status?: string}
 */
function user_account_status_change(
    PDO $pdo,
    int $targetUserId,
    string $action,
    string $reason,
    int $performedBy
): array {
    user_account_status_ensure_schema($pdo);

    $action = strtolower(trim($action));
    $reason = trim($reason);
    $isRestore = ($action === 'restore');

    if (!$isRestore && $reason === '') {
        return ['success' => false, 'message' => 'A reason is required for this action.'];
    }

    if (!$isRestore && mb_strlen($reason) < 5) {
        return ['success' => false, 'message' => 'Please provide a more detailed reason (at least 5 characters).'];
    }

    if ($isRestore && $reason !== '' && mb_strlen($reason) < 5) {
        return ['success' => false, 'message' => 'Please provide a more detailed restore reason (at least 5 characters).'];
    }

    $validActions = ['approve', 'verify', 'activate', 'deactivate', 'suspend', 'reactivate', 'reject', 'archive', 'restore'];
    if (!in_array($action, $validActions, true)) {
        return ['success' => false, 'message' => 'Invalid account status action.'];
    }

    if ($action === 'verify') {
        $action = 'approve';
    }

    $performerRole = user_account_status_user_role($pdo, $performedBy);
    if ($performerRole === 'admin' && $action !== 'archive') {
        return ['success' => false, 'message' => 'Only the Super Administrator can perform this action.'];
    }

    if ($action === 'restore' && $performerRole !== 'superadmin') {
        return ['success' => false, 'message' => 'Only the Super Administrator can restore archived accounts.'];
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_active, u.account_status,
               pp.verification_status
        FROM users u
        LEFT JOIN provider_profiles pp ON pp.user_id = u.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$targetUserId]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        return ['success' => false, 'message' => 'User account not found.'];
    }

    if ($target['role'] === 'superadmin') {
        return ['success' => false, 'message' => 'Super Administrator accounts cannot be modified through this action.'];
    }

    if ($performerRole === 'admin' && ($target['role'] ?? '') === 'admin') {
        return ['success' => false, 'message' => 'Administrators cannot archive other administrator accounts.'];
    }

    if ($targetUserId === $performedBy && in_array($action, ['deactivate', 'suspend', 'archive', 'reject'], true)) {
        return ['success' => false, 'message' => 'You cannot perform this action on your own account.'];
    }

    $previousStatus = user_account_status_effective($target);
    $allowed = user_account_status_allowed_actions($previousStatus);

    if ($action === 'activate' && $previousStatus === AccountStatus::SUSPENDED) {
        $action = 'reactivate';
    }

    if (!in_array($action, $allowed, true)) {
        return [
            'success' => false,
            'message' => 'Action "' . user_account_status_action_label($action) . '" is not allowed for accounts with status "' . AccountStatus::label($previousStatus) . '".',
        ];
    }

    $newStatus = user_account_status_action_to_status($action, $previousStatus);
    if ($newStatus === null) {
        return ['success' => false, 'message' => 'Unable to determine new account status.'];
    }

    if ($newStatus === $previousStatus && $action !== 'approve') {
        return ['success' => false, 'message' => 'Account is already in the requested state.'];
    }

    $isActive = user_account_status_sync_is_active($newStatus);

    $pdo->prepare('UPDATE users SET account_status = ?, is_active = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$newStatus, $isActive, $targetUserId]);

    user_account_status_apply_metadata($pdo, $targetUserId, $action, $reason, $performedBy);

    if (($target['role'] ?? '') === 'provider' && in_array($action, ['approve', 'activate', 'reactivate'], true)) {
        require_once __DIR__ . '/provider_verification.php';
        provider_verification_ensure_schema($pdo);
        $pdo->prepare("
            UPDATE provider_profiles
            SET verification_status = 'verified', verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE user_id = ? AND verification_status IN ('pending', 'rejected')
        ")->execute([$performedBy, $targetUserId]);
    }

    if (($target['role'] ?? '') === 'provider' && $action === 'reject') {
        require_once __DIR__ . '/provider_verification.php';
        provider_verification_ensure_schema($pdo);
        $pdo->prepare("
            UPDATE provider_profiles
            SET verification_status = 'rejected', verified_by = ?, verified_at = NOW(),
                rejection_note = ?, updated_at = NOW()
            WHERE user_id = ?
        ")->execute([$performedBy, $reason, $targetUserId]);
    }

    $performerName = user_account_status_display_name($pdo, $performedBy);
    $targetName = trim($target['first_name'] . ' ' . $target['last_name']);
    $performerRole = user_account_status_user_role($pdo, $performedBy);
    $performerRoleLabel = $performerRole === 'superadmin' ? 'Super Administrator' : ucfirst($performerRole);

    $logReason = $reason !== '' ? $reason : ($action === 'restore' ? 'Account restored by Super Administrator.' : $reason);

    user_account_status_write_log($pdo, [
        'target_user_id'   => $targetUserId,
        'target_user_name' => $targetName,
        'target_user_role' => (string) $target['role'],
        'previous_status'  => $previousStatus,
        'new_status'       => $newStatus,
        'action_performed' => $action,
        'reason'           => $logReason,
        'performed_by'     => $performedBy,
        'performed_by_name'=> $performerName,
    ]);

    $roleLabel = match ($target['role']) {
        'provider' => 'Dr.',
        'bhw'      => 'BHW',
        'admin'    => 'Administrator',
        default    => ucfirst((string) $target['role']),
    };

    $actionPast = match ($action) {
        'approve'    => 'approved',
        'activate'   => 'activated',
        'reactivate' => 'reactivated',
        'deactivate' => 'deactivated',
        'suspend'    => 'suspended',
        'reject'     => 'rejected',
        'archive'    => 'archived',
        'restore'    => 'restored',
        default      => $action . 'd',
    };

    $logReason = $reason !== '' ? $reason : ($action === 'restore' ? 'Account restored by Super Administrator.' : $reason);
    $description = "{$performerRoleLabel} {$performerName} {$actionPast} the account of {$roleLabel} {$targetName}.";
    if ($logReason !== '') {
        $description .= " Reason: {$logReason}.";
    }

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $performedBy,
        'action_type' => $action === 'restore' ? AuditAction::ACCOUNT_RESTORED : AuditAction::ACCOUNT_STATUS_CHANGED,
        'description' => $description,
        'meta'        => [
            'target_user_id'   => $targetUserId,
            'target_user_name' => $targetName,
            'target_user_role' => $target['role'],
            'previous_status'  => $previousStatus,
            'new_status'       => $newStatus,
            'action_performed' => $action,
            'reason'           => $logReason,
            'performed_by'     => $performedBy,
            'performed_by_name'=> $performerName,
        ],
    ]);

    if ($action === 'restore') {
        user_account_status_notify_restored($pdo, $targetUserId, $targetName, (string) $target['email'], $performedBy);
    }

    return [
        'success'         => true,
        'message'         => $action === 'restore'
            ? 'Account restored successfully. The user can sign in again.'
            : 'Account status updated to ' . AccountStatus::label($newStatus) . '.',
        'previous_status' => $previousStatus,
        'new_status'      => $newStatus,
    ];
}

/** @param array<string, mixed> $entry */
function user_account_status_write_log(PDO $pdo, array $entry): void
{
    require_once __DIR__ . '/audit_log.php';

    $ip = _audit_get_ip();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_account_status_logs
                (target_user_id, target_user_name, target_user_role, previous_status, new_status,
                 action_performed, reason, performed_by, performed_by_name, ip_address, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            (int) $entry['target_user_id'],
            (string) $entry['target_user_name'],
            (string) $entry['target_user_role'],
            AccountStatus::normalize((string) $entry['previous_status']),
            AccountStatus::normalize((string) $entry['new_status']),
            strtolower((string) $entry['action_performed']),
            (string) $entry['reason'],
            (int) $entry['performed_by'],
            (string) $entry['performed_by_name'],
            $ip !== 'unknown' ? $ip : null,
        ]);
    } catch (PDOException $e) {
        audit_log($pdo, [
            'patient_id'  => (int) $entry['performed_by'],
            'action_type' => 'account_status_log_failed',
            'description' => 'Failed to write user_account_status_logs entry.',
            'meta'        => $entry,
        ]);
    }
}

function user_account_status_display_name(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? trim($row['first_name'] . ' ' . $row['last_name']) : 'Unknown User';
}

function user_account_status_user_role(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();

    return $role ? (string) $role : 'unknown';
}

/**
 * Set account status directly when creating users (does not require superadmin session).
 */
function user_account_status_set(PDO $pdo, int $userId, string $status): void
{
    user_account_status_ensure_schema($pdo);
    $status = AccountStatus::normalize($status);
    $isActive = user_account_status_sync_is_active($status);
    $pdo->prepare('UPDATE users SET account_status = ?, is_active = ? WHERE id = ?')
        ->execute([$status, $isActive, $userId]);
}

function user_account_status_apply_metadata(PDO $pdo, int $userId, string $action, string $reason, int $performedBy): void
{
    if ($action === 'archive') {
        $pdo->prepare("
            UPDATE users
            SET archived_at = NOW(),
                archived_by = ?,
                archive_reason = ?,
                restored_at = NULL,
                restored_by = NULL,
                restore_reason = NULL
            WHERE id = ?
        ")->execute([$performedBy, $reason, $userId]);
        return;
    }

    if ($action === 'restore') {
        $restoreReason = $reason !== '' ? $reason : null;
        $pdo->prepare('
            UPDATE users
            SET restored_at = NOW(),
                restored_by = ?,
                restore_reason = ?
            WHERE id = ?
        ')->execute([$performedBy, $restoreReason, $userId]);
    }
}

function user_account_status_notify_restored(PDO $pdo, int $userId, string $name, string $email, int $restoredBy): void
{
    try {
        require_once __DIR__ . '/notification_events.php';
        $role = user_account_status_user_role($pdo, $userId);
        $title = 'Account Restored';
        $message = 'Your MEDCONNECT account has been restored. You may sign in again.';

        match ($role) {
            'patient'  => NotificationManager::notifyPatient($pdo, $userId, [
                'type' => NotificationManager::TYPE_SYSTEM,
                'title' => $title,
                'message' => $message,
                'priority' => 'high',
            ]),
            'provider' => NotificationManager::notifyProvider($pdo, $userId, [
                'type' => NotificationManager::TYPE_SYSTEM,
                'title' => $title,
                'message' => $message,
                'priority' => 'high',
            ]),
            'bhw'      => NotificationManager::notifyBhw($pdo, $userId, [
                'type' => NotificationManager::TYPE_SYSTEM,
                'title' => $title,
                'message' => $message,
                'priority' => 'high',
            ]),
            default    => null,
        };
    } catch (Throwable $e) {
        // Non-fatal.
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function user_account_status_fetch_users(PDO $pdo, array $options = []): array
{
    user_account_status_ensure_schema($pdo);

    $search = trim((string) ($options['search'] ?? ''));
    $roleFilter = (string) ($options['role'] ?? 'all');
    $statusFilter = (string) ($options['status'] ?? 'all');
    $sort = (string) ($options['sort'] ?? '');
    $order = strtolower((string) ($options['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $archivedOnly = ($statusFilter === 'archived');

    $query = "
        SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.is_active, u.account_status,
               u.profile_picture, u.created_at,
               u.archived_at, u.archived_by, u.archive_reason,
               u.restored_at, u.restored_by, u.restore_reason,
               pp.verification_status, pp.prc_license_number,
               ab.first_name AS archiver_first_name, ab.last_name AS archiver_last_name
        FROM users u
        LEFT JOIN provider_profiles pp ON pp.user_id = u.id
        LEFT JOIN users ab ON ab.id = u.archived_by
        WHERE u.role != 'superadmin'
    ";
    $params = [];

    $dbRole = user_account_role_filter_to_db($roleFilter);
    if ($dbRole !== null) {
        $query .= ' AND u.role = ?';
        $params[] = $dbRole;
    }

    if ($archivedOnly) {
        $query .= " AND u.account_status = 'archived'";
    } elseif ($statusFilter !== 'all') {
        $query .= " AND u.account_status != 'archived'";
    } else {
        $query .= " AND u.account_status != 'archived'";
    }

    if ($search !== '') {
        $query .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?)';
        $s = '%' . $search . '%';
        array_push($params, $s, $s, $s, $s);
    }

    if ($archivedOnly) {
        $sortCol = match ($sort) {
            'name'        => 'u.last_name ' . $order . ', u.first_name ' . $order,
            'role'        => 'u.role ' . $order . ', u.last_name ASC',
            'archived_by' => 'ab.last_name ' . $order . ', ab.first_name ' . $order,
            default       => 'u.archived_at ' . $order,
        };
        $query .= ' ORDER BY ' . $sortCol;
    } else {
        $query .= ' ORDER BY u.created_at DESC';
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$archivedOnly && $statusFilter !== 'all') {
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => user_account_status_matches_filter(
                user_account_status_effective($row),
                $statusFilter
            )
        ));
    }

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function user_account_status_get_details(PDO $pdo, int $userId): ?array
{
    user_account_status_ensure_schema($pdo);

    $stmt = $pdo->prepare("
        SELECT u.*,
               pp.verification_status, pp.prc_license_number,
               ab.first_name AS archiver_first_name, ab.last_name AS archiver_last_name,
               rb.first_name AS restorer_first_name, rb.last_name AS restorer_last_name
        FROM users u
        LEFT JOIN provider_profiles pp ON pp.user_id = u.id
        LEFT JOIN users ab ON ab.id = u.archived_by
        LEFT JOIN users rb ON rb.id = u.restored_by
        WHERE u.id = ? AND u.role != 'superadmin'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function user_account_status_audit_history(PDO $pdo, int $userId, int $limit = 50): array
{
    user_account_status_ensure_schema($pdo);
    $limit = max(1, min(100, $limit));

    $stmt = $pdo->prepare("
        SELECT *
        FROM user_account_status_logs
        WHERE target_user_id = ?
        ORDER BY created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
