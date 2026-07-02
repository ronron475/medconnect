<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/config/db.php';

$patient_id = (int) ($argv[1] ?? 2);
$_SESSION['user_id'] = $patient_id;
$_SESSION['user_role'] = 'patient';

$slot = $pdo->query("SELECT id, provider_id, slot_date, start_time FROM appointment_slots WHERE status='available' AND slot_date >= CURDATE() ORDER BY slot_date, start_time LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$slot) {
    echo "No available slot\n";
    exit(1);
}

echo "Using patient $patient_id slot " . json_encode($slot) . PHP_EOL;

$open = $pdo->prepare("SELECT id, status FROM consultations WHERE patient_id = ? AND status IN ('pending','scheduled','in_consultation')");
$open->execute([$patient_id]);
echo "Open consultations: " . json_encode($open->fetchAll(PDO::FETCH_ASSOC)) . PHP_EOL;

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = [
    'chief_complaint' => 'Fever and cough for 2 days',
    'symptoms' => ['fever', 'cough'],
    'slot_id' => (string) $slot['id'],
];

ob_start();
try {
    include dirname(__DIR__, 2) . '/app/api/patient/submit_triage.php';
} catch (Throwable $e) {
    ob_end_clean();
    echo "THROW: " . $e->getMessage() . PHP_EOL;
}
$out = ob_get_clean();
echo $out . PHP_EOL;
