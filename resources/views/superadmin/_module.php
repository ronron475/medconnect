<?php
/**
 * Bridge: Super Admin portal shell + shared admin module body.
 *
 * Superadmin pages include this file so they reuse views/admin/*.php
 * while keeping the Super Admin sidebar and auth bootstrap.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once BASE_PATH . '/app/includes/portal_paths.php';
define('MC_PORTAL_SHELL', 'superadmin');

$entry = portal_current_view_basename();

$adminAliases = [
    'reports.php' => 'analytics.php',
    'user_activity.php' => 'audit_logs.php',
    'audit_trail.php' => 'audit_logs.php',
    'landing_page.php' => 'website_dashboard.php',
    'system_health.php' => 'health_monitor.php',
    'bhw_applications.php' => 'bhw_applications.php',
    'doctor_applications.php' => 'doctor_applications.php',
];

$adminFile = $adminAliases[$entry] ?? $entry;
$adminPath = VIEWS_PATH . '/admin/' . $adminFile;

if (!is_readable($adminPath)) {
    http_response_code(404);
    $page_title = 'Module Not Found';
    require_once __DIR__ . '/partials/layout_open.php';
    echo '<div class="mc-card"><p class="text-muted">This module is not available yet.</p></div>';
    require_once __DIR__ . '/partials/layout_close.php';
    exit;
}

require $adminPath;
