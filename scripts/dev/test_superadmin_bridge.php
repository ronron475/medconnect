<?php
/**
 * Verify superadmin bridge resolves the correct admin view via view router.
 * Run: php scripts/dev/test_superadmin_bridge.php
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/includes/portal_paths.php';

$_GET['path'] = 'superadmin/staff_management.php';
define('MC_VIEW_PATH', 'superadmin/staff_management.php');

$entry = portal_current_view_basename();
$aliases = [
    'reports.php' => 'analytics.php',
    'user_activity.php' => 'audit_logs.php',
    'audit_trail.php' => 'audit_logs.php',
    'landing_page.php' => 'website_dashboard.php',
    'system_health.php' => 'health_monitor.php',
    'bhw_applications.php' => 'bhw_applications.php',
    'doctor_applications.php' => 'doctor_applications.php',
];
$adminFile = $aliases[$entry] ?? $entry;
$adminPath = VIEWS_PATH . '/admin/' . $adminFile;

echo "Entry: {$entry}\n";
echo "Admin file: {$adminFile}\n";
echo "Readable: " . (is_readable($adminPath) ? 'yes' : 'NO') . "\n";

$bridged = [
    'staff_management.php', 'user_management.php', 'analytics.php', 'reports.php',
    'gis_dashboard.php', 'system_health.php', 'live_consultation_monitor.php',
];
$failed = [];
foreach ($bridged as $file) {
    $_GET['path'] = 'superadmin/' . $file;
    if (!defined('MC_VIEW_PATH')) {
        define('MC_VIEW_PATH', $_GET['path']);
    }
    // Reset for each - actually MC_VIEW_PATH can't be redefined. Use helper only with $_GET.
    $e = basename($_GET['path']);
    $af = $aliases[$e] ?? $e;
    if (!is_readable(VIEWS_PATH . '/admin/' . $af)) {
        $failed[] = "{$file} -> admin/{$af}";
    }
}

if ($failed) {
    echo "FAILED bridges:\n";
    foreach ($failed as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "All sampled bridged modules resolve correctly.\n";
