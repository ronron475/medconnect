<?php
declare(strict_types=1);

/**
 * Shared portal navigation helpers — active-state detection for sectioned nav menus.
 */

function portal_nav_is_active(string $file, string $current, string $query, ?string $itemQuery): bool
{
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

function portal_nav_current_basename(): string
{
    require_once BASE_PATH . '/app/includes/portal_paths.php';
    return portal_current_view_basename();
}

function portal_nav_current_query(): string
{
    return (string) ($_SERVER['QUERY_STRING'] ?? '');
}
