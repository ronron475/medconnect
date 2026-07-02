<?php
declare(strict_types=1);

/**
 * Portal role helpers — admin vs superadmin without breaking existing admin checks.
 */

function portal_role(): string
{
    return (string) ($_SESSION['user_role'] ?? '');
}

function portal_is_superadmin(): bool
{
    return portal_role() === 'superadmin';
}

function portal_is_admin(): bool
{
    return portal_role() === 'admin';
}

function portal_is_admin_portal(): bool
{
    return in_array(portal_role(), ['admin', 'superadmin'], true);
}

function portal_api_require_admin_portal(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!portal_is_admin_portal()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
}

function portal_api_require_superadmin(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!portal_is_superadmin()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Super Admin access required.']);
        exit;
    }
}

/** Super Administrators may perform all account status actions. */
function portal_can_change_account_status(): bool
{
    return portal_is_superadmin();
}

/** Administrators may archive accounts only (server enforces action whitelist). */
function portal_can_archive_account(PDO $pdo, int $targetUserId): bool
{
    if (!portal_is_admin_portal()) {
        return false;
    }
    if (!portal_can_manage_user($pdo, $targetUserId)) {
        return false;
    }
    if ($targetUserId === (int) ($_SESSION['user_id'] ?? 0)) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetUserId]);
    $targetRole = (string) ($stmt->fetchColumn() ?: '');
    if (in_array($targetRole, ['admin', 'superadmin'], true) && !portal_is_superadmin()) {
        return false;
    }
    return true;
}

/** Regular admins cannot modify superadmin accounts. */
function portal_can_manage_user(PDO $pdo, int $targetUserId): bool
{
    if ($targetUserId === (int) ($_SESSION['user_id'] ?? 0)) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$targetUserId]);
    $targetRole = $stmt->fetchColumn();
    if ($targetRole === 'superadmin' && !portal_is_superadmin()) {
        return false;
    }
    return portal_is_admin_portal();
}

function portal_superadmin_layout_path(): string
{
    return VIEWS_PATH . '/superadmin/partials/layout_open.php';
}

function portal_admin_layout_path(): string
{
    return VIEWS_PATH . '/admin/partials/layout_open.php';
}
