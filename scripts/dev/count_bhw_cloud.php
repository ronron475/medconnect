<?php
// Force cloud DB path the same way production PHP does when not on localhost.
putenv('DB_ENV=cloud');
$_ENV['DB_ENV'] = 'cloud';

require dirname(__DIR__, 2) . '/bootstrap.php';
require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/app/includes/admin_dashboard_charts.php';

echo 'DB_HOST=' . DB_HOST . PHP_EOL;
echo 'DB_NAME=' . DB_NAME . PHP_EOL;
echo 'DATABASE()=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;

$map = admin_chart_role_counts_map($pdo);
echo "=== production/cloud role map ===\n";
foreach ($map as $k => $v) {
    echo "$k=$v\n";
}
echo 'sum=' . array_sum($map) . PHP_EOL;

try {
    echo 'bhw_applications=' . (int) $pdo->query('SELECT COUNT(*) FROM bhw_applications')->fetchColumn() . PHP_EOL;
} catch (Throwable $e) {
    echo 'apps_err=' . $e->getMessage() . PHP_EOL;
}

$rows = $pdo->query('SELECT role, COUNT(*) n FROM users GROUP BY role ORDER BY n DESC')->fetchAll(PDO::FETCH_ASSOC);
echo "=== raw roles ===\n";
foreach ($rows as $r) {
    echo json_encode($r['role']) . ' => ' . $r['n'] . "\n";
}
