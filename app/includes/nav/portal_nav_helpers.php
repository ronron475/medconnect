<?php
declare(strict_types=1);

/**
 * Shared portal navigation helpers — active-state detection for sectioned nav menus.
 */

function portal_nav_is_active(string $file, string $current, string $query, ?string $itemQuery, ?string $navGroup = null): bool
{
    if ($navGroup !== null && $navGroup !== '') {
        return portal_nav_group_is_active($navGroup, $current, $query);
    }

    if ($current !== $file) {
        return false;
    }
    if ($itemQuery === null || $itemQuery === '') {
        // Base route only — sibling items with itemQuery (role=, tab=referral, etc.) own those states.
        parse_str($query, $actual);
        if (($actual['role'] ?? '') !== '') {
            return false;
        }
        $tab = trim((string) ($actual['tab'] ?? ''));
        if ($tab !== '' && $tab !== 'facilities') {
            return false;
        }
        return true;
    }
    parse_str($itemQuery, $expected);
    parse_str($query, $actual);
    foreach ($expected as $k => $v) {
        if (($actual[$k] ?? '') !== $v) {
            return false;
        }
    }
    return true;
}

/**
 * Highlight a consolidated management nav item across related routes.
 */
function portal_nav_group_is_active(string $group, string $current, string $query): bool
{
    parse_str($query, $params);

    return match ($group) {
        'doctor_management' => $current === 'doctor_applications.php'
            || $current === 'doctor_approvals.php'
            || ($current === 'staff_management.php' && ($params['role'] ?? '') === 'provider'),
        'doctor_verification' => $current === 'doctor_applications.php'
            || $current === 'doctor_approvals.php',
        'consultation_monitoring' => $current === 'live_consultation_monitor.php'
            || $current === 'queue_monitoring.php',
        'bhw_management' => $current === 'bhw_applications.php'
            || $current === 'bhw_approvals.php'
            || ($current === 'staff_management.php' && ($params['role'] ?? '') === 'bhw'),
        'bhw_verification' => $current === 'bhw_applications.php'
            || $current === 'bhw_approvals.php',
        'patient_management' => $current === 'user_management.php'
            && ($params['role'] ?? '') === 'patient',
        'administrator_management' => $current === 'user_management.php' && ($params['role'] ?? '') === 'admin',
        default => false,
    };
}

function portal_nav_current_basename(): string
{
    require_once BASE_PATH . '/app/includes/portal_paths.php';
    return portal_current_view_basename();
}

function portal_nav_current_query(): string
{
    return (string) ($_SERVER['QUERY_STRING'] ?? '');
}

/**
 * Relative view path under a portal segment (e.g. patients/list.php for BHW).
 */
function portal_nav_current_portal_path(string $segment): string
{
    $segment = trim($segment, '/');
    $normalize = static function (string $path) use ($segment): string {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        $prefix = $segment . '/';
        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }
        return ltrim($path, '/');
    };

    if (defined('MC_VIEW_PATH') && MC_VIEW_PATH !== '') {
        $path = $normalize((string) MC_VIEW_PATH);
        if ($path !== '') {
            return $path;
        }
    }

    $routePath = (string) ($_GET['path'] ?? '');
    if ($routePath !== '') {
        $path = $normalize($routePath);
        if ($path !== '') {
            return $path;
        }
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (preg_match('#/views/' . preg_quote($segment, '#') . '/([^?#]+)#', $uri, $match)) {
        return ltrim(str_replace('\\', '/', $match[1]), '/');
    }

    $script = (string) ($_SERVER['PHP_SELF'] ?? '');
    if (preg_match('#/views/' . preg_quote($segment, '#') . '/(.+)$#', str_replace('\\', '/', $script), $match)) {
        return ltrim($match[1], '/');
    }

    return portal_nav_current_basename();
}

/** BHW nested-route active state (supports legacy aliases via bhw_nav_resolve_file). */
function portal_nav_bhw_is_active(string $file, string $currentPath): bool
{
    require_once BASE_PATH . '/app/includes/nav/bhw_nav.php';

    $file = ltrim(str_replace('\\', '/', $file), '/');
    $currentPath = ltrim(str_replace('\\', '/', $currentPath), '/');
    $resolvedFile = bhw_nav_resolve_file($file);
    $resolvedCurrent = bhw_nav_resolve_file($currentPath);

    if ($resolvedFile === $resolvedCurrent || $file === $currentPath) {
        return true;
    }
    if (basename($resolvedCurrent) === $resolvedFile || basename($resolvedCurrent) === $file) {
        // Only match bare basename when the nav item itself is a bare file (e.g. dashboard.php).
        if (!str_contains($resolvedFile, '/') && !str_contains($file, '/')) {
            return true;
        }
    }
    if (str_ends_with($currentPath, '/' . $file) || str_ends_with($resolvedCurrent, '/' . $resolvedFile)) {
        return true;
    }

    return false;
}
