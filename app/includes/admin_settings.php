<?php
/**
 * Admin / superadmin account security helpers (sessions, logout all devices).
 */
declare(strict_types=1);

require_once __DIR__ . '/remember_me.php';
require_once __DIR__ . '/superadmin/schema.php';
require_once __DIR__ . '/superadmin/security.php';

function admin_settings_require_staff(): int
{
    $role = (string) ($_SESSION['user_role'] ?? '');
    if (empty($_SESSION['user_id']) || !in_array($role, ['admin', 'superadmin'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }

    return (int) $_SESSION['user_id'];
}

function admin_settings_require_admin(): int
{
    $role = (string) ($_SESSION['user_role'] ?? '');
    if (empty($_SESSION['user_id']) || $role !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only an administrator can change this.']);
        exit;
    }

    return (int) $_SESSION['user_id'];
}

function admin_settings_staff_role(): string
{
    $role = (string) ($_SESSION['user_role'] ?? 'admin');
    return in_array($role, ['admin', 'superadmin'], true) ? $role : 'admin';
}

function admin_settings_verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid request token. Refresh the page and try again.']);
        exit;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function admin_settings_list_sessions(PDO $pdo, int $userId, ?string $role = null): array
{
    $role = $role ?? admin_settings_staff_role();
    $currentSid = session_id();
    $rows = [];

    try {
        remember_me_ensure_schema($pdo);
        $stmt = $pdo->prepare("
            SELECT id, session_id, ip_address, user_agent, browser, device, last_activity, created_at
            FROM active_sessions
            WHERE user_id = ? AND role = ?
              AND last_activity >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY last_activity DESC
            LIMIT 20
        ");
        $stmt->execute([$userId, $role]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }

    return array_map(static function (array $row) use ($currentSid): array {
        return [
            'id' => (int) $row['id'],
            'is_current' => ($row['session_id'] ?? '') === $currentSid,
            'browser' => (string) ($row['browser'] ?? 'Unknown'),
            'device' => (string) ($row['device'] ?? 'desktop'),
            'ip_address' => (string) ($row['ip_address'] ?? ''),
            'last_activity_label' => !empty($row['last_activity'])
                ? date('M j, Y', strtotime((string) $row['last_activity'])) . ' • ' . date('g:i A', strtotime((string) $row['last_activity']))
                : '—',
        ];
    }, $rows);
}

/**
 * @return array{success: bool, message: string}
 */
function admin_settings_logout_all_devices(PDO $pdo, int $userId, ?string $role = null): array
{
    $role = $role ?? admin_settings_staff_role();

    try {
        $pdo->prepare('DELETE FROM active_sessions WHERE user_id = ? AND role = ?')->execute([$userId, $role]);
    } catch (PDOException $e) { /* non-fatal */ }

    try {
        remember_me_revoke_for_user($pdo, $userId);
    } catch (PDOException $e) { /* non-fatal */ }

    remember_me_clear_cookie();
    unset($_SESSION['remember_me_extended']);

    superadmin_security_log(
        $pdo,
        'logout_all_devices',
        'account_security',
        'success',
        ucfirst($role) . ' signed out of all devices from profile settings.',
        $userId,
        $role
    );

    return ['success' => true, 'message' => 'All other devices have been signed out.'];
}

/**
 * @return array{hash: string}|array{success: false, message: string}
 */
function admin_settings_verify_own_password(PDO $pdo, int $userId, string $current): array
{
    if ($current === '') {
        return ['success' => false, 'message' => 'Enter your current password.'];
    }
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
    $stmt->execute([$userId]);
    $hash = (string) ($stmt->fetchColumn() ?: '');
    if ($hash === '' || !password_verify($current, $hash)) {
        return ['success' => false, 'message' => 'Current password is incorrect.'];
    }

    return ['hash' => $hash];
}

/**
 * @return array{success: bool, message: string}
 */
function admin_settings_change_password(PDO $pdo, int $userId, string $current, string $newPass, string $confirm): array
{
    if ($current === '' || $newPass === '' || $confirm === '') {
        return ['success' => false, 'message' => 'All password fields are required.'];
    }
    if ($newPass !== $confirm) {
        return ['success' => false, 'message' => 'New password and confirmation do not match.'];
    }

    require_once __DIR__ . '/patient_account_security.php';
    require_once __DIR__ . '/password_history.php';
    patient_security_ensure_schema($pdo);

    $policyError = patient_validate_password_policy($newPass);
    if ($policyError !== null) {
        return ['success' => false, 'message' => $policyError];
    }

    $checked = admin_settings_verify_own_password($pdo, $userId, $current);
    if (isset($checked['success']) && $checked['success'] === false) {
        return $checked;
    }
    $hash = (string) $checked['hash'];
    if (password_verify($newPass, $hash)) {
        return ['success' => false, 'message' => 'New password must be different from your current password.'];
    }
    if (password_history_is_reused($pdo, $userId, $newPass)) {
        return ['success' => false, 'message' => 'You cannot reuse a recent password. Choose a different one.'];
    }

    password_history_add($pdo, $userId, $hash);
    $newHash = patient_hash_password($newPass);
    $pdo->prepare("
        UPDATE users
        SET password = ?, password_changed_at = NOW(), updated_at = NOW()
        WHERE id = ? AND role = 'admin'
    ")->execute([$newHash, $userId]);

    remember_me_revoke_for_user($pdo, $userId);
    remember_me_clear_cookie();

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => AuditAction::PASSWORD_CHANGED,
        'description' => 'Administrator changed their password from Settings.',
        'meta'        => ['source' => 'admin_settings', 'role' => 'admin'],
    ]);

    return ['success' => true, 'message' => 'Password updated. Use it the next time you sign in.'];
}

/**
 * @return array{success: bool, message: string, email?: string}
 */
function admin_settings_change_gmail(PDO $pdo, int $userId, string $currentPassword, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['success' => false, 'message' => 'Enter a valid Gmail address.'];
    }
    if (!preg_match('/@gmail\.com$/', $email)) {
        return ['success' => false, 'message' => 'Use a Gmail address ending in @gmail.com.'];
    }

    $checked = admin_settings_verify_own_password($pdo, $userId, $currentPassword);
    if (isset($checked['success']) && $checked['success'] === false) {
        return $checked;
    }

    $currentStmt = $pdo->prepare("SELECT email FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
    $currentStmt->execute([$userId]);
    $currentEmail = strtolower(trim((string) ($currentStmt->fetchColumn() ?: '')));
    if ($currentEmail === $email) {
        return ['success' => false, 'message' => 'That is already your Gmail address.'];
    }

    $taken = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
    $taken->execute([$email, $userId]);
    if ($taken->fetchColumn()) {
        return ['success' => false, 'message' => 'That Gmail address is already used by another account.'];
    }

    $pdo->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ? AND role = 'admin'")
        ->execute([$email, $userId]);
    $_SESSION['user_email'] = $email;
    $_SESSION['email'] = $email;

    require_once __DIR__ . '/audit_log.php';
    audit_log($pdo, [
        'patient_id'  => $userId,
        'action_type' => AuditAction::ACCOUNT_UPDATE,
        'description' => 'Administrator changed their Gmail address from Settings.',
        'meta'        => ['source' => 'admin_settings', 'role' => 'admin'],
    ]);

    return ['success' => true, 'message' => 'Gmail updated. Use it the next time you sign in.', 'email' => $email];
}
