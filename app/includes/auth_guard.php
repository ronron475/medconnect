<?php
/**
 * Centralized session role guards for view pages.
 */
require_once __DIR__ . '/portal_auth.php';

function auth_prevent_back_cache(): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    }
}

function auth_landing_url(array $params = []): string
{
    $url = BASE_URL . '/index.php';
    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}

/** Landing URL that opens sign-in with context (not the same as session timeout). */
function auth_signin_required_url(): string
{
    return auth_landing_url(['signin' => '1']);
}

function auth_session_expired_url(): string
{
    return auth_landing_url(['session_expired' => '1']);
}

function auth_account_deactivated_url(): string
{
    return auth_landing_url(['account_deactivated' => '1']);
}

/**
 * Canonical JSON payload for expired/invalid authenticated sessions.
 * Keep `code` for existing clients; `error` mirrors the same signal.
 *
 * @return array{success:bool,authenticated:bool,error:string,message:string,code:string,redirect:string}
 */
function auth_session_expired_payload(?string $redirect = null): array
{
    $redirect = $redirect ?: auth_session_expired_url();
    return [
        'success' => false,
        'authenticated' => false,
        'error' => 'SESSION_EXPIRED',
        'message' => 'Your session has expired. Please log in again.',
        'code' => 'session_expired',
        'redirect' => $redirect,
    ];
}

/**
 * @return array{success:bool,authenticated:bool,error:string,message:string,code:string,redirect:string}
 */
function auth_account_deactivated_payload(?string $redirect = null): array
{
    if (!function_exists('user_account_deactivated_message')) {
        require_once __DIR__ . '/user_account_status.php';
    }
    $redirect = $redirect ?: auth_account_deactivated_url();
    return [
        'success' => false,
        'authenticated' => false,
        'error' => 'ACCOUNT_DEACTIVATED',
        'message' => user_account_deactivated_message(),
        'code' => 'account_deactivated',
        'redirect' => $redirect,
    ];
}

/**
 * Emit 401 JSON or HTML redirect for an expired/invalid session.
 * Does not clear the session by itself — callers clear first when needed.
 */
function auth_respond_session_expired(?string $redirect = null): void
{
    $redirect = $redirect ?: auth_session_expired_url();
    require_once BASE_PATH . '/app/includes/request_helpers.php';
    if (request_wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        http_response_code(401);
        echo json_encode(auth_session_expired_payload($redirect), JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . $redirect);
    exit;
}

/**
 * Emit 401/redirect for a deactivated account (session already cleared by caller).
 */
function auth_respond_account_deactivated(?string $redirect = null): void
{
    $payload = auth_account_deactivated_payload($redirect);
    require_once BASE_PATH . '/app/includes/request_helpers.php';
    if (request_wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        http_response_code(401);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Location: ' . $payload['redirect']);
    exit;
}

/**
 * Clear session and return visitor to the public landing / sign-in flow.
 */
function auth_destroy_session_and_redirect(string $reason = 'signin'): void
{
    if (session_status() === PHP_SESSION_NONE) {
        if (!function_exists('medconnect_session_start')) {
            require_once __DIR__ . '/session_cookie.php';
        }
        medconnect_session_start();
    }

    $_SESSION = [];
    if (!function_exists('medconnect_expire_session_cookie')) {
        require_once __DIR__ . '/session_cookie.php';
    }
    medconnect_expire_session_cookie();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    if ($reason === 'account_deactivated') {
        auth_respond_account_deactivated(auth_account_deactivated_url());
    }

    if ($reason === 'session_expired' || $reason === 'session_invalid') {
        auth_respond_session_expired(auth_session_expired_url());
    }

    $redirect = auth_signin_required_url();
    require_once BASE_PATH . '/app/includes/request_helpers.php';
    if (request_wants_json()) {
        // Still use the canonical session-expired contract for API clients.
        auth_respond_session_expired($redirect);
    }

    header('Location: ' . $redirect);
    exit;
}

/**
 * Reject sessions whose user row was removed or may no longer sign in.
 * Deactivation applies only to that specific account.
 */
function auth_ensure_session_user_valid(PDO $pdo): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }

    require_once __DIR__ . '/user_account_status.php';
    user_account_status_ensure_schema($pdo);

    $userId = (int) $_SESSION['user_id'];
    $role = (string) ($_SESSION['user_role'] ?? '');
    $stmt = $pdo->prepare('SELECT id, role, is_active, account_status, session_epoch FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        auth_destroy_session_and_redirect('session_invalid');
    }

    if ($role !== '' && (string) ($row['role'] ?? '') !== $role) {
        auth_destroy_session_and_redirect('session_invalid');
    }

    // Session epoch bumps on deactivate — kicks this user only, even if their PHP session file remains.
    if (array_key_exists('session_epoch', $_SESSION)) {
        $sessionEpoch = (int) $_SESSION['session_epoch'];
        $dbEpoch = (int) ($row['session_epoch'] ?? 1);
        if ($sessionEpoch > 0 && $sessionEpoch !== $dbEpoch) {
            if (!user_account_login_allowed_for_row($row)) {
                $status = user_account_status_effective($row);
                auth_destroy_session_and_redirect(
                    $status === AccountStatus::DEACTIVATED ? 'account_deactivated' : 'session_invalid'
                );
            }
            // Stale session after reactivation: end this session and require a fresh login.
            auth_destroy_session_and_redirect('session_invalid');
        }
    }

    if (!user_account_login_allowed_for_row($row)) {
        $status = user_account_status_effective($row);
        if ($status === AccountStatus::DEACTIVATED) {
            auth_destroy_session_and_redirect('account_deactivated');
        }
        auth_destroy_session_and_redirect('session_invalid');
    }

    // Legacy sessions created before session_epoch tracking: stamp current epoch once.
    if (!array_key_exists('session_epoch', $_SESSION)) {
        $_SESSION['session_epoch'] = (int) ($row['session_epoch'] ?? 1);
    }
}

function auth_portal_dashboard_url(?string $role = null): ?string
{
    $role = $role ?? (string) ($_SESSION['user_role'] ?? '');
    return match ($role) {
        'patient'    => ASSET_BASE . '/views/patient/dashboard.php',
        'provider'   => ASSET_BASE . '/views/provider/dashboard.php',
        'admin'      => ASSET_BASE . '/views/admin/dashboard.php',
        'superadmin' => ASSET_BASE . '/views/superadmin/dashboard.php',
        'bhw'        => ASSET_BASE . '/views/bhw/dashboard.php',
        default      => null,
    };
}

/**
 * Send already-authenticated users to their portal (skip public landing).
 */
function auth_redirect_if_logged_in(): void
{
    auth_prevent_back_cache();
    if (session_status() === PHP_SESSION_NONE) {
        if (!function_exists('medconnect_session_start')) {
            require_once __DIR__ . '/session_cookie.php';
        }
        medconnect_session_start();
    }
    if (empty($_SESSION['user_id'])) {
        return;
    }

    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        auth_ensure_session_user_valid($pdo);
    }

    // Keep landing for post-registration, password-setup, session-timeout, or deactivation messaging.
    if (
        isset($_GET['registered'])
        || isset($_GET['setup_complete'])
        || isset($_GET['session_expired'])
        || isset($_GET['account_deactivated'])
    ) {
        return;
    }

    $url = auth_portal_dashboard_url();
    if ($url === null) {
        return;
    }

    if (($_SESSION['user_role'] ?? '') === 'patient') {
        global $pdo;
        if (isset($pdo) && $pdo instanceof PDO) {
            require_once BASE_PATH . '/app/includes/patient_account_security.php';
            if (patient_requires_account_setup($pdo, (int) $_SESSION['user_id'])) {
                $url = ASSET_BASE . '/views/patient/account_setup.php';
            }
        }
    }

    header('Location: ' . $url);
    exit;
}

function auth_is_superadmin(): bool
{
    return portal_is_superadmin();
}

function auth_is_admin_portal(): bool
{
    return portal_is_admin_portal();
}

function auth_require_superadmin(): void
{
    auth_require_role('superadmin');
}

function auth_require_login(): void
{
    auth_prevent_back_cache();
    if (session_status() === PHP_SESSION_NONE) {
        if (!function_exists('medconnect_session_start')) {
            require_once __DIR__ . '/session_cookie.php';
        }
        medconnect_session_start();
    }
    require_once BASE_PATH . '/app/includes/session_timeout.php';
    session_timeout_check();
    if (empty($_SESSION['user_id'])) {
        require_once BASE_PATH . '/app/includes/request_helpers.php';
        if (request_wants_json()) {
            auth_respond_session_expired();
        }
        header('Location: ' . auth_signin_required_url());
        exit;
    }
    if (empty($_SESSION['authenticated'])) {
        $_SESSION['authenticated'] = true;
    }

    global $pdo;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        require_once BASE_PATH . '/config/db.php';
    }
    auth_ensure_session_user_valid($pdo);
}

function auth_require_role(string|array $roles): void
{
    auth_require_login();
    $allowed = is_array($roles) ? $roles : [$roles];
    $current = (string) ($_SESSION['user_role'] ?? '');
    if (!in_array($current, $allowed, true)) {
        require_once BASE_PATH . '/app/includes/request_helpers.php';
        $redirect = auth_signin_required_url();
        if (request_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Forbidden.',
                'code' => 'forbidden',
                'redirect' => $redirect,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: ' . $redirect);
        exit;
    }
}

function auth_csrf_validate(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || $token === null || $token === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function auth_csrf_require(): void
{
    $token = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!auth_csrf_validate($token)) {
        require_once __DIR__ . '/request_helpers.php';
        if (request_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid request token. Refresh the page and try again.',
                'code' => 'csrf_invalid',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(403);
        echo 'Invalid request token.';
        exit;
    }
}
