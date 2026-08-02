<?php
declare(strict_types=1);

/**
 * Role-aware portal layout loader for shared pages (notifications, security, etc.).
 */

require_once BASE_PATH . '/app/includes/portal_auth.php';

function portal_layout_role(): string
{
    return (string) ($_SESSION['user_role'] ?? 'patient');
}

function portal_layout_open(?string $role = null): void
{
    $role = $role ?? portal_layout_role();

    switch ($role) {
        case 'admin':
            require_once VIEWS_PATH . '/admin/partials/layout_open.php';
            break;
        case 'superadmin':
            require_once VIEWS_PATH . '/superadmin/partials/layout_open.php';
            break;
        case 'provider':
            require_once VIEWS_PATH . '/provider/partials/icons.php';
            require_once VIEWS_PATH . '/provider/partials/data.php';
            require_once VIEWS_PATH . '/provider/partials/layout_open.php';
            break;
        case 'bhw':
            require_once VIEWS_PATH . '/bhw/partials/layout_open.php';
            break;
        default:
            if (!defined('MC_PATIENT_LAYOUT_HEAD_LOADED')) {
                echo "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
                require_once VIEWS_PATH . '/patient/partials/layout_head.php';
                if (is_file(VIEWS_PATH . '/partials/notification_assets.php')) {
                    require_once VIEWS_PATH . '/partials/notification_assets.php';
                }
                echo "</head>\n<body class=\"patient-portal\" data-portal=\"patient\">\n";
                define('MC_PATIENT_LAYOUT_HEAD_LOADED', true);
            }
            require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php';
            break;
    }
}

function portal_layout_close(?string $role = null): void
{
    $role = $role ?? portal_layout_role();

    switch ($role) {
        case 'admin':
            require_once VIEWS_PATH . '/admin/partials/layout_close.php';
            break;
        case 'superadmin':
            require_once VIEWS_PATH . '/superadmin/partials/layout_close.php';
            break;
        case 'provider':
            require_once VIEWS_PATH . '/provider/partials/layout_close.php';
            break;
        case 'bhw':
            require_once VIEWS_PATH . '/bhw/partials/layout_close.php';
            break;
        default:
            require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php';
            break;
    }
}
