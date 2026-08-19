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
        return $query === '' || !str_contains($query, 'role=');
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
            || ($current === 'staff_management.php' && ($params['role'] ?? '') === 'provider'),
        'bhw_management' => $current === 'bhw_applications.php'
            || ($current === 'staff_management.php' && ($params['role'] ?? '') === 'bhw'),
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
