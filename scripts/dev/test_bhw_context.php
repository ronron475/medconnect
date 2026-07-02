<?php
session_start();
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once VIEWS_PATH . '/bhw/partials/bhw_context.php';

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND role = ? LIMIT 1');
$stmt->execute(['bhw@medconnect.local', 'bhw']);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "FAIL no BHW user\n";
    exit(1);
}

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['user_role'] = 'bhw';
unset($_SESSION['user_barangay_id'], $_SESSION['user_barangay_name']);

$ctx = bhw_resolve_context($pdo);
echo json_encode($ctx, JSON_PRETTY_PRINT) . PHP_EOL;
