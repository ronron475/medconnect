<?php
/**
 * Diagnose and fix login for a patient by email.
 * Usage: php scripts/dev/fix_patient_login.php mlronaldgonzales@gmail.com [new_password]
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$email = $argv[1] ?? 'mlronaldgonzales@gmail.com';
$newPassword = $argv[2] ?? 'Patient@1234';

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "ERROR: No user found for {$email}\n";
    exit(1);
}

echo "User found:\n";
echo "  id={$user['id']} role={$user['role']} is_active={$user['is_active']}\n";

$columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
foreach (['is_email_verified', 'email_verified_at', 'phone'] as $col) {
    if (in_array($col, $columns, true)) {
        echo "  {$col}=" . ($user[$col] ?? 'NULL') . "\n";
    }
}

// Check patient_registrations if table exists
if ($pdo->query("SHOW TABLES LIKE 'patient_registrations'")->rowCount()) {
    $pr = $pdo->prepare('SELECT status, email FROM patient_registrations WHERE email = ? LIMIT 1');
    $pr->execute([$email]);
    $reg = $pr->fetch(PDO::FETCH_ASSOC);
    if ($reg) {
        echo "  patient_registrations status={$reg['status']}\n";
        if ($reg['status'] !== 'verified') {
            $pdo->prepare("UPDATE patient_registrations SET status = 'verified', verified_at = NOW() WHERE email = ?")
                ->execute([$email]);
            echo "OK  Set patient_registrations to verified\n";
        }
    } else {
        echo "  (no patient_registrations row — OK if optional)\n";
    }
}

$hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
$updateSql = 'UPDATE users SET password = ?, is_active = 1, role = ?';
$params = [$hash, 'patient'];

if (in_array('is_email_verified', $columns, true)) {
    $updateSql .= ', is_email_verified = 1, email_verified_at = COALESCE(email_verified_at, NOW())';
}
$updateSql .= ' WHERE email = ?';
$params[] = $email;

$pdo->prepare($updateSql)->execute($params);

$verify = $pdo->prepare('SELECT password FROM users WHERE email = ?');
$verify->execute([$email]);
$row = $verify->fetch();

if (!password_verify($newPassword, $row['password'])) {
    echo "FAIL: password could not be verified after update\n";
    exit(1);
}

echo "\nFIXED — sign in with:\n";
echo "  Email:    {$email}\n";
echo "  Password: {$newPassword}\n";
echo "  URL:      " . BASE_URL . "/index.php\n";
