<?php
/**
 * Dev-only: verify which plaintext passwords match users table hashes.
 * Run: php scripts/dev/check_login_passwords.php
 */
require_once dirname(__DIR__, 2) . '/config/db.php';

$candidates = [
    'password', 'Password', 'Admin@1234', 'admin123', 'Provider@12345',
    'provider123', 'Provider@1234', 'patient123', 'Patient@1234',
    'test123', 'medconnect', '123456', 'admin', 'bhw12345', 'Test@1234',
    'password123', 'secret', 'changeme', 'Medconnect@123', 'admin@1234',
];

$users = $pdo->query('SELECT id, email, password, role, is_active FROM users ORDER BY id')->fetchAll();

echo "Users in database:\n";
foreach ($users as $u) {
    echo sprintf("  [%d] %s (%s) active=%s\n", $u['id'], $u['email'], $u['role'], $u['is_active']);
}

echo "\nPassword matches:\n";
$any = false;
foreach ($users as $u) {
    foreach ($candidates as $p) {
        if (password_verify($p, $u['password'])) {
            echo "  {$u['email']} => \"{$p}\"\n";
            $any = true;
        }
    }
}
if (!$any) {
    echo "  (none of the common test passwords matched)\n";
}
