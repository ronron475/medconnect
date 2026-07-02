<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
$email = 'mlronaldgonzales@gmail.com';
$u = $pdo->prepare('SELECT id, email, role, is_active FROM users WHERE email = ?');
$u->execute([$email]);
print_r($u->fetch(PDO::FETCH_ASSOC));
if ($pdo->query("SHOW TABLES LIKE 'patient_registrations'")->rowCount()) {
    $pr = $pdo->prepare('SELECT id, status, email, full_name FROM patient_registrations WHERE email = ?');
    $pr->execute([$email]);
    print_r($pr->fetch(PDO::FETCH_ASSOC));
}
