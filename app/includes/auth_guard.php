<?php
/**
 * Centralized session role guards for view pages.
 */
require_once __DIR__ . '/portal_auth.php';

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
        $redirect = BASE_URL . '/index.php';
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
        $redirect = BASE_URL . '/index.php';
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
