<?php
/**
 * Reset known dev account passwords (local XAMPP only).
 * Run: php scripts/dev/reset_dev_passwords.php
 */
require_once dirname(__DIR__, 2) . '/config/db.php';

$accounts = [
    'admin@medconnect.local'   => 'Admin@1234',
    'provider@medconnect.com'  => 'Provider@12345',
    'bhw@medconnect.local'     => 'bhw@1234',
    'testpatient1780501328@medconnect.local' => 'password',
    'testpatient1780501730@medconnect.local' => 'password',
    'mlronaldgonzales@gmail.com'           => 'Patient@1234',
];

$stmt = $pdo->prepare('UPDATE users SET password = ?, is_active = 1 WHERE email = ?');

echo "Resetting passwords:\n";
foreach ($accounts as $email => $plain) {
    $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt->execute([$hash, $email]);
    $n = $stmt->rowCount();
    echo $n ? "  OK  {$email} => {$plain}\n" : "  SKIP (not found) {$email}\n";
}

echo "\nUse these at sign-in (field is Email, not username):\n";
foreach ($accounts as $email => $plain) {
    echo "  {$email} / {$plain}\n";
}
