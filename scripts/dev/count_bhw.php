<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
require_once BASE_PATH . '/config/db.php';
require_once BASE_PATH . '/app/includes/admin_dashboard_charts.php';

echo 'DB_ENV=' . (getenv('DB_ENV') ?: '(unset)') . PHP_EOL;
echo 'DB_HOST=' . DB_HOST . PHP_EOL;
echo 'DB_NAME=' . DB_NAME . PHP_EOL;
echo 'DATABASE()=' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo 'SAPI=' . PHP_SAPI . PHP_EOL;

echo "=== role counts (chart map) ===\n";
$map = admin_chart_role_counts_map($pdo);
foreach ($map as $k => $v) {
    echo "$k=$v\n";
}
echo 'sum=' . array_sum($map) . PHP_EOL;

echo "=== raw role groups ===\n";
$rows = $pdo->query('SELECT role, COUNT(*) n FROM users GROUP BY role ORDER BY n DESC')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo json_encode($r['role']) . ' => ' . $r['n'] . "\n";
}

echo "=== bhw detail statuses ===\n";
$q = $pdo->query("SELECT COALESCE(account_status,'(NULL)') st, COUNT(*) n FROM users WHERE LOWER(TRIM(role))='bhw' GROUP BY account_status");
while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
    echo $r['st'] . '=' . $r['n'] . "\n";
}

echo "=== bhw applications ===\n";
try {
    echo 'apps=' . (int) $pdo->query('SELECT COUNT(*) FROM bhw_applications')->fetchColumn() . PHP_EOL;
} catch (Throwable $e) {
    echo 'apps_err=' . $e->getMessage() . PHP_EOL;
}

echo "=== chart payload roles ===\n";
foreach (admin_dashboard_chart_payload($pdo, 30)['roles'] as $r) {
    echo $r['role'] . '=' . $r['count'] . PHP_EOL;
}
