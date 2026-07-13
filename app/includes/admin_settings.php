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
                ? date('M j, Y g:i A', strtotime((string) $row['last_activity']))
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
