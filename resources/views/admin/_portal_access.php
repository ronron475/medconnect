<?php
/**
 * Shared access gate for admin view modules (also used when embedded in Super Admin shell).
 */
require_once BASE_PATH . '/app/includes/auth_guard.php';
require_once BASE_PATH . '/app/includes/portal_paths.php';
auth_require_role(['admin', 'superadmin']);

if (portal_is_superadmin() && !defined('MC_PORTAL_SHELL')) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $base = portal_current_view_basename();
    $target = portal_superadmin_redirect_aliases()[$base] ?? $base;
    $url = ASSET_BASE . '/views/superadmin/' . $target;
    if ($qs !== '') {
        $url .= '?' . $qs;
    }
    header('Location: ' . $url);
    exit;
}
