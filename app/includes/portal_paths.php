<?php
declare(strict_types=1);

/**
 * Portal-aware view paths for Admin vs Super Admin shells.
 */

/** Basename of the active portal view (e.g. staff_management.php). */
function portal_current_view_basename(): string
{
    if (defined('MC_VIEW_PATH') && MC_VIEW_PATH !== '') {
        return basename(str_replace('\\', '/', (string) MC_VIEW_PATH));
    }

    $routePath = (string) ($_GET['path'] ?? '');
    if ($routePath !== '') {
        return basename(str_replace('\\', '/', $routePath));
    }

    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    $base = basename(str_replace('\\', '/', $script));
    if ($base === 'view.php') {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (preg_match('#/views/[^/]+/([^/?]+)#', $uri, $match)) {
            return basename($match[1]);
        }
    }

    return $base;
}

function portal_is_superadmin_shell(): bool
{
    return defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin';
}

function portal_views_base(): string
{
    $segment = portal_is_superadmin_shell() || portal_is_superadmin() ? 'superadmin' : 'admin';
    return ASSET_BASE . '/views/' . $segment;
}

/** @return array<string, string> superadmin filename => target filename */
function portal_superadmin_redirect_aliases(): array
{
    return [
        'bhw_applications.php'     => 'bhw_approvals.php',
        'doctor_applications.php'    => 'doctor_approvals.php',
    ];
}

function portal_superadmin_view_url(string $adminBasename, ?string $query = null): string
{
    $file = portal_superadmin_redirect_aliases()[$adminBasename] ?? $adminBasename;
    $url = ASSET_BASE . '/views/superadmin/' . $file;
    if ($query !== null && $query !== '') {
        $url .= (str_starts_with($query, '?') ? '' : '?') . $query;
    }
    return $url;
}

function portal_view_url(string $adminBasename, ?string $query = null): string
{
    if (portal_is_superadmin_shell() || portal_is_superadmin()) {
        return portal_superadmin_view_url($adminBasename, $query);
    }
    $url = ASSET_BASE . '/views/admin/' . $adminBasename;
    if ($query !== null && $query !== '') {
        $url .= (str_starts_with($query, '?') ? '' : '?') . $query;
    }
    return $url;
}
