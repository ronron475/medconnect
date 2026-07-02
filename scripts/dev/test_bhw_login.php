<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
$stmt = $pdo->prepare('SELECT password, role FROM users WHERE email = ?');
$stmt->execute(['bhw@medconnect.local']);
$u = $stmt->fetch();
echo password_verify('bhw@1234', $u['password']) ? "PASS login\n" : "FAIL login\n";
echo "role={$u['role']}\n";
