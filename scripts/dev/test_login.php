<?php
require_once dirname(__DIR__, 2) . '/config/db.php';

$tests = [
    ['admin@medconnect.local', 'Admin@1234'],
    ['provider@medconnect.com', 'Provider@12345'],
    ['testpatient1780501328@medconnect.local', 'password'],
];

foreach ($tests as [$email, $pass]) {
    $stmt = $pdo->prepare('SELECT password, role FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    $ok = $u && password_verify($pass, $u['password']);
    echo ($ok ? 'PASS' : 'FAIL') . " {$email} ({$u['role']})\n";
}
