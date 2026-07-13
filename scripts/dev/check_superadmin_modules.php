<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/superadmin/schema.php';

superadmin_ensure_schema($pdo);

echo "=== Superadmin users ===\n";
$stmt = $pdo->query("SELECT id, email, role, first_name FROM users WHERE role='superadmin' LIMIT 10");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($users);

$aliases = [
    'reports.php' => 'analytics.php',
    'user_activity.php' => 'audit_logs.php',
    'audit_trail.php' => 'audit_logs.php',
    'landing_page.php' => 'website_dashboard.php',
    'system_health.php' => 'health_monitor.php',
    'bhw_applications.php' => 'bhw_applications.php',
    'doctor_applications.php' => 'doctor_applications.php',
];

$nav = require BASE_PATH . '/app/includes/nav/superadmin_nav.php';
$missing = [];
$ok = [];

foreach ($nav as $section) {
    foreach ($section['items'] as $item) {
        $file = $item[0];
        $saPath = VIEWS_PATH . '/superadmin/' . $file;
        if (!is_readable($saPath)) {
            $missing[] = "superadmin/$file (view missing)";
            continue;
        }
        $content = file_get_contents($saPath);
        if (str_contains($content, "_module.php")) {
            $adminFile = $aliases[$file] ?? $file;
            $adminPath = VIEWS_PATH . '/admin/' . $adminFile;
            if (!is_readable($adminPath)) {
                $missing[] = "$file -> admin/$adminFile (bridge target missing)";
            } else {
                $ok[] = "$file (bridged -> admin/$adminFile)";
            }
        } else {
            $ok[] = "$file (native)";
        }
    }
}

echo "\n=== Missing modules (" . count($missing) . ") ===\n";
foreach ($missing as $m) echo "  - $m\n";

echo "\n=== Working modules (" . count($ok) . ") ===\n";
