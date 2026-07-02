<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once dirname(dirname(dirname(__DIR__))) . '/bootstrap.php';
require_once dirname(dirname(dirname(__DIR__))) . '/config/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';
require_once BASE_PATH . '/app/includes/superadmin/service.php';

$stats = superadmin_dashboard_stats($pdo);
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
$threads = 0;
$uptime = 0;
try {
    $status = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Uptime')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $threads = (int) ($status['Threads_connected'] ?? 0);
    $uptime = (int) ($status['Uptime'] ?? 0);
} catch (Throwable $e) {}

$tables = $pdo->query("
    SELECT table_name, table_rows, ROUND((data_length+index_length)/1024/1024,2) AS size_mb
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
    ORDER BY (data_length+index_length) DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'timestamp' => date('c'),
    'database' => (string) $dbName,
    'version' => (string) $version,
    'total_size_mb' => $stats['database_size_mb'],
    'threads' => $threads,
    'uptime_seconds' => $uptime,
    'tables' => $tables,
]);
