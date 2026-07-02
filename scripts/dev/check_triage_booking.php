<?php
require dirname(__DIR__, 2) . '/bootstrap.php';
require dirname(__DIR__, 2) . '/config/db.php';

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (['triage_results', 'appointment_slots', 'consultations', 'users'] as $t) {
    echo $t . ': ' . (in_array($t, $tables, true) ? 'yes' : 'NO') . PHP_EOL;
}

if (in_array('appointment_slots', $tables, true)) {
    $cols = $pdo->query('SHOW COLUMNS FROM appointment_slots')->fetchAll(PDO::FETCH_COLUMN);
    echo 'appointment_slots cols: ' . implode(', ', $cols) . PHP_EOL;
    $cnt = $pdo->query("SELECT COUNT(*) FROM appointment_slots WHERE status='available' AND slot_date >= CURDATE()")->fetchColumn();
    echo 'available future slots: ' . $cnt . PHP_EOL;
}

if (in_array('consultations', $tables, true)) {
    $cols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN);
    echo 'consultations cols: ' . implode(', ', $cols) . PHP_EOL;
}

if (in_array('triage_results', $tables, true)) {
    $cols = $pdo->query('SHOW COLUMNS FROM triage_results')->fetchAll(PDO::FETCH_COLUMN);
    echo 'triage_results cols: ' . implode(', ', $cols) . PHP_EOL;
}

$patients = $pdo->query("SELECT id, email, role FROM users WHERE role='patient' LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
echo 'patients: ' . json_encode($patients) . PHP_EOL;

$providers = $pdo->query("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM users WHERE role='provider' AND is_active=1")->fetchAll(PDO::FETCH_ASSOC);
echo 'providers: ' . json_encode($providers) . PHP_EOL;

foreach ([2, 5] as $pid) {
    $open = $pdo->prepare("SELECT id, status, consult_date, consult_time FROM consultations WHERE patient_id = ? AND status IN ('pending','scheduled','in_consultation')");
    $open->execute([$pid]);
    echo "patient $pid open consults: " . json_encode($open->fetchAll(PDO::FETCH_ASSOC)) . PHP_EOL;
}
