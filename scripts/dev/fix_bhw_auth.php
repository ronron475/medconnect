<?php
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$email = 'bhw@medconnect.local';
$password = 'bhw@1234';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

$stmt = $pdo->prepare("UPDATE users SET password = ?, is_active = 1 WHERE email = ?");
$stmt->execute([$hash, $email]);

if ($stmt->rowCount() > 0) {
    echo "Password updated successfully for $email\n";
} else {
    echo "No changes made or user not found.\n";
}
