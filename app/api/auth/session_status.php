<?php
/**
 * Lightweight auth probe for cross-tab session sync.
 * Server session remains the authority — this only reports current state.
 * Also reports account deactivation so protected UIs can force logout.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/auth_guard.php';
require_once BASE_PATH . '/app/includes/user_account_status.php';

$uid = (int) ($_SESSION['user_id'] ?? 0);
$authenticated = $uid > 0;
if ($authenticated && empty($_SESSION['authenticated'])) {
    $_SESSION['authenticated'] = true;
}

$payload = [
    'success' => true,
    'authenticated' => $authenticated,
];

if ($authenticated) {
    try {
        user_account_status_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT id, role, is_active, account_status FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !user_account_login_allowed_for_row($row)) {
            $status = $row ? user_account_status_effective($row) : AccountStatus::DEACTIVATED;
            // Clear only this browser session; other users are unaffected.
            $_SESSION = [];
            if (function_exists('medconnect_expire_session_cookie')) {
                medconnect_expire_session_cookie();
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            $deactivated = !$row || $status === AccountStatus::DEACTIVATED;
            $payload = $deactivated
                ? auth_account_deactivated_payload()
                : auth_session_expired_payload();
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        // Fall through with session-based auth if probe DB fails.
    }
    $payload['user_id'] = $uid;
    $payload['role'] = (string) ($_SESSION['user_role'] ?? '');
} else {
    $expired = auth_session_expired_payload();
    $payload['success'] = false;
    $payload['error'] = $expired['error'];
    $payload['message'] = $expired['message'];
    $payload['code'] = $expired['code'];
    $payload['redirect'] = $expired['redirect'];
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE);
exit;
