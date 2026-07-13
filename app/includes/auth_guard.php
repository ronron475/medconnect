<?php
/**
 * Centralized session role guards for view pages.
 */
require_once __DIR__ . '/portal_auth.php';

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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        return;
    }

    // Keep landing for post-registration, password-setup, or session-timeout messaging.
    if (isset($_GET['registered']) || isset($_GET['setup_complete']) || isset($_GET['session_expired'])) {
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
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once BASE_PATH . '/app/includes/session_timeout.php';
    session_timeout_check();
    if (empty($_SESSION['user_id'])) {
        require_once BASE_PATH . '/app/includes/request_helpers.php';
        $redirect = auth_signin_required_url();
        if (request_wants_json()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized.',
                'code' => 'unauthorized',
                'redirect' => $redirect,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: ' . $redirect);
        exit;
    }
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
