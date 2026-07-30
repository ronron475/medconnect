<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/config/db.php';

$rows = $pdo->query(
    'SELECT id, email, contact_number, status, created_at FROM patient_registrations ORDER BY id DESC LIMIT 10'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
