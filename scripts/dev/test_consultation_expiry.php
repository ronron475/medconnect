<?php
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/consultation_expiry.php';

$patientId = (int) ($argv[1] ?? 5);
echo 'Before:' . PHP_EOL;
$rows = $pdo->prepare("SELECT id, consult_date, consult_time, status FROM consultations WHERE patient_id = ? ORDER BY id DESC LIMIT 5");
$rows->execute([$patientId]);
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . PHP_EOL;
}

$count = consultations_auto_expire($pdo, $patientId);
echo 'Expired: ' . $count . PHP_EOL;

echo 'After:' . PHP_EOL;
$rows->execute([$patientId]);
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row) . PHP_EOL;
}
