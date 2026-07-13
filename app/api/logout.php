<?php
/**
 * API: Logout handler
 * URL: /app/api/logout.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/includes/remember_me.php';

// ── Audit Log ───────────────────────────────────────────────
if (!empty($_SESSION['user_id'])) {
    try {
        require_once dirname(__DIR__, 2) . '/config/db.php';
        $logoutUserId = (int) $_SESSION['user_id'];
        require_once BASE_PATH . '/app/includes/audit_log.php';
        if (($_SESSION['user_role'] ?? '') === 'bhw') {
            require_once dirname(dirname(__DIR__)) . '/app/includes/bhw_activity.php';
            bhw_activity_log($pdo, 'bhw_logout', 'BHW logged out of the portal.');
        }
        audit_log($pdo, [
            'patient_id'  => $_SESSION['user_id'],
            'action_type' => AuditAction::LOGOUT,
            'description' => 'User logged out.',
        ]);
        try {
            require_once dirname(dirname(__DIR__)) . '/app/includes/superadmin/security.php';
            superadmin_clear_session($pdo, $logoutUserId);
        } catch (Throwable $e) { /* non-fatal */ }
    } catch (Exception $e) { /* non-fatal */ }
}

// Revoke remember-me token (best-effort)
try {
    remember_me_revoke_current_cookie($pdo);
} catch (Throwable $e) { /* non-fatal */ }

$_SESSION = [];
session_unset();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool) ($params['secure'] ?? false),
        true
    );
}
session_destroy();

$wantsJson = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

$redirect = BASE_URL . '/index.php';

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'redirect' => $redirect]);
    exit;
}

header('Location: ' . $redirect);
exit;
