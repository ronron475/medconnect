<?php
/**
 * One-off: verify provider assignment vs schedules for a patient.
 * Usage: php scripts/dev/check_doctor_assignment.php [patient_email_or_name]
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/app/includes/appointment_slots.php';
require_once dirname(__DIR__, 2) . '/app/includes/triage_provider_assignment.php';

date_default_timezone_set('Asia/Manila');

$needle = $argv[1] ?? 'Yuma';

echo 'Today: ' . date('Y-m-d l H:i:s') . PHP_EOL;

echo PHP_EOL . '=== PROVIDERS ===' . PHP_EOL;
$providers = $pdo->query("SELECT id, first_name, last_name, email, is_active FROM users WHERE role='provider' ORDER BY id")->fetchAll();
foreach ($providers as $p) {
    echo "#{$p['id']} {$p['first_name']} {$p['last_name']} ({$p['email']}) active={$p['is_active']}" . PHP_EOL;
}

echo PHP_EOL . '=== PATIENT (search: ' . $needle . ') ===' . PHP_EOL;
$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email
    FROM users
    WHERE role = 'patient'
      AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
    LIMIT 5
");
$like = '%' . $needle . '%';
$stmt->execute([$like, $like, $like]);
$patients = $stmt->fetchAll();
if ($patients === []) {
    echo "No patient found.\n";
    exit(1);
}
foreach ($patients as $patient) {
    echo "#{$patient['id']} {$patient['first_name']} {$patient['last_name']} ({$patient['email']})" . PHP_EOL;
}

$patientId = (int) $patients[0]['id'];

echo PHP_EOL . "=== TRIAGE for patient #{$patientId} ===" . PHP_EOL;
$tri = $pdo->prepare("
    SELECT tr.id, tr.assigned_provider_id, tr.chief_complaint, tr.recommendation_status, tr.assessed_at,
           CONCAT(u.first_name, ' ', u.last_name) AS provider_name
    FROM triage_results tr
    LEFT JOIN users u ON u.id = tr.assigned_provider_id
    WHERE tr.patient_id = ?
    ORDER BY tr.assessed_at DESC
    LIMIT 5
");
$tri->execute([$patientId]);
foreach ($tri->fetchAll() as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$ctx = triage_patient_review_booking_context($pdo, $patientId);
$status = triage_patient_booking_slot_status($pdo, $patientId);
echo PHP_EOL . '=== BOOKING CONTEXT ===' . PHP_EOL;
echo json_encode($ctx, JSON_PRETTY_PRINT) . PHP_EOL;
echo PHP_EOL . '=== SLOT STATUS ===' . PHP_EOL;
echo json_encode($status, JSON_PRETTY_PRINT) . PHP_EOL;

$day = date('l');
echo PHP_EOL . "=== PROVIDER SCHEDULES ({$day}) ===" . PHP_EOL;
foreach ($providers as $p) {
    $id = (int) $p['id'];
    $sched = $pdo->prepare('SELECT * FROM provider_schedules WHERE provider_id = ? AND day_of_week = ? ORDER BY sort_order');
    $sched->execute([$id, $day]);
    $rows = $sched->fetchAll();
    echo "Dr {$p['first_name']} {$p['last_name']} (#{$id}): " . count($rows) . ' session(s)' . PHP_EOL;
    foreach ($rows as $r) {
        echo "  {$r['start_time']}-{$r['end_time']} dur={$r['slot_duration']} active={$r['is_active']}" . PHP_EOL;
    }
}

echo PHP_EOL . '=== APPOINTMENT SLOTS TODAY ===' . PHP_EOL;
$bookableSql = appointment_slots_bookable_sql('s');
foreach ($providers as $p) {
    $id = (int) $p['id'];
    appointment_slots_sync_today($pdo, $id);
    appointment_slots_expire_passed($pdo, $id);

    $stmt = $pdo->prepare('
        SELECT s.id, s.start_time, s.end_time, s.status
        FROM appointment_slots s
        WHERE s.provider_id = ? AND s.slot_date = CURDATE()
        ORDER BY s.start_time
    ');
    $stmt->execute([$id]);
    $all = $stmt->fetchAll();

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM appointment_slots s WHERE s.provider_id = ? AND s.status = 'available' AND {$bookableSql}");
    $stmt2->execute([$id]);
    $bookableCount = (int) $stmt2->fetchColumn();

    $avail = array_filter($all, static fn(array $r): bool => $r['status'] === 'available');
    echo "Dr {$p['first_name']} {$p['last_name']}: total=" . count($all) . ' available=' . count($avail) . " bookable_now={$bookableCount}" . PHP_EOL;
    foreach ($all as $s) {
        echo "  {$s['start_time']}-{$s['end_time']} status={$s['status']}" . PHP_EOL;
    }
}
