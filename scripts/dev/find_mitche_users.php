<?php
require_once dirname(__DIR__, 2) . '/config/db.php';

echo "DB: " . ($pdo->query('SELECT DATABASE()')->fetchColumn()) . "\n\n";

$patterns = ['%Mitche%', '%Yuma%', '%mitche%', '%yuma%', '%gonzales%'];

echo "=== users ===\n";
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email, role, created_at
    FROM users
    WHERE first_name LIKE ? OR last_name LIKE ?
       OR email LIKE ? OR email LIKE ? OR email LIKE ?
       OR CONCAT(first_name, ' ', last_name) LIKE '%Mitche Ann%'
    ORDER BY id
");
$stmt->execute($patterns);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($users);

echo "\n=== patient_registrations ===\n";
$stmt = $pdo->prepare("
    SELECT id, user_id, email, first_name, last_name, full_name, patient_code, created_at
    FROM patient_registrations
    WHERE first_name LIKE ? OR last_name LIKE ? OR full_name LIKE ? OR email LIKE ?
    ORDER BY id
");
$stmt->execute(['%Mitche%', '%Yuma%', '%Mitche%', '%gonzales%']);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
