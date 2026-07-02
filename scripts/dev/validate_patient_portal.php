<?php
/**
 * CLI: validate patient account data for portal display.
 * Usage: php scripts/dev/validate_patient_portal.php [email]
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/db.php';

$email = $argv[1] ?? 'mlronaldgonzales@gmail.com';

$stmt = $pdo->prepare("SELECT id, first_name, last_name, email, role, is_active FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    fwrite(STDERR, "FAIL: User not found: {$email}\n");
    exit(1);
}

if ($user['role'] !== 'patient') {
    fwrite(STDERR, "FAIL: Role is {$user['role']}, expected patient\n");
    exit(1);
}

$uid = (int) $user['id'];
echo "OK: User #{$uid} {$user['first_name']} {$user['last_name']} (active=" . ($user['is_active'] ? 'yes' : 'no') . ")\n";

$reg = $pdo->prepare("SELECT contact_number, barangay, blood_type FROM patient_registrations WHERE email = ? LIMIT 1");
$reg->execute([$email]);
$pr = $reg->fetch(PDO::FETCH_ASSOC);
echo $pr ? "OK: patient_registrations linked by email\n" : "WARN: No patient_registrations row for this email\n";

$tables = ['consultations', 'triage_results', 'prescriptions', 'clinical_notes', 'digital_referrals'];
foreach ($tables as $t) {
    if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->rowCount() === 0) {
        echo "SKIP: table {$t} missing\n";
        continue;
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM {$t} WHERE patient_id = ?");
    $c->execute([$uid]);
    echo "INFO: {$t} count = " . $c->fetchColumn() . "\n";
}

echo "Done.\n";
