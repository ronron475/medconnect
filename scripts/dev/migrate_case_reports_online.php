<?php
$hosts = [
    'mysql.hostinger.com',
    'srv1844.hstgr.io',
    'auth-db1844.hstgr.io',
];
$db = 'u520834156_meDBConnect26';
$user = 'u520834156_usrMedConnect';
$pass = '0#KQFw#m;p@V';

foreach ($hosts as $host) {
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_TIMEOUT => 8,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        echo "{$host}: OK\n";
        require dirname(__DIR__, 2) . '/app/includes/case_reports_schema.php';
        case_reports_ensure_schema($pdo);
        $cols = $pdo->query('SHOW COLUMNS FROM case_reports')->fetchAll(PDO::FETCH_COLUMN);
        echo 'Columns: ' . implode(', ', $cols) . "\n";
        echo "Online migration OK.\n";
        exit(0);
    } catch (Throwable $e) {
        echo "{$host}: FAIL - " . substr($e->getMessage(), 0, 120) . "\n";
    }
}
exit(1);
