<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/views/patient/dashboard.php';
$_GET['path'] = 'patient/dashboard.php';

require_once dirname(__DIR__, 2) . '/config/db.php';

$uid = (int) ($pdo->query("SELECT id FROM users WHERE role='patient' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
if ($uid <= 0) {
    echo "No patient users in local DB. Creating temp check with uid=15 simulation skipped.\n";
    exit(1);
}

session_start();
$_SESSION['user_id'] = (int) ($argv[1] ?? $uid);
$_SESSION['user_role'] = 'patient';
$_SESSION['csrf_token'] = 'test';

ob_start();
try {
    require dirname(__DIR__, 2) . '/resources/views/patient/dashboard.php';
    $out = ob_get_clean();
    echo 'OK bytes=' . strlen($out) . "\n";
    if (strlen($out) < 200) {
        echo $out;
    }
} catch (Throwable $e) {
    ob_end_clean();
    echo 'ERROR: ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}
