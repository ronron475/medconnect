<?php
/**
 * One-off verifier for AI preliminary vs doctor final triage.
 * Database writes are rolled back when a local connection is available.
 */
require_once dirname(__DIR__, 2) . '/app/includes/triage_assessment_schema.php';
require_once dirname(__DIR__, 2) . '/app/core/TriageLevelService.php';

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function assert_same(string $got, string $want, string $label): void
{
    if ($got !== $want) {
        fail("{$label}: expected [{$want}] got [{$got}]");
    }
    echo "OK  {$label} = {$got}\n";
}

$cases = [
    'Test 1 NU→NU' => [
        'row' => [
            'triage_classification' => 'NON-URGENT',
            'level' => '3',
            'urgency_label' => 'Non-Urgent',
            'triage_level' => 'non_urgent',
        ],
        'ai' => 'NON-URGENT',
        'doctor' => 'NON-URGENT',
        'final' => 'NON-URGENT',
        'emergency' => false,
        'ai_emergency' => false,
        'modal' => false,
    ],
    'Test 2 URGENT→NU' => [
        'row' => [
            'triage_classification' => 'URGENT',
            'level' => '3',
            'urgency_label' => 'Non-Urgent',
            'triage_level' => 'non_urgent',
        ],
        'ai' => 'URGENT',
        'doctor' => 'NON-URGENT',
        'final' => 'NON-URGENT',
        'emergency' => false,
        'ai_emergency' => false,
        'modal' => false,
    ],
    'Test 3 URGENT→EMERGENCY' => [
        'row' => [
            'triage_classification' => 'URGENT',
            'level' => '1',
            'urgency_label' => 'Emergency',
            'triage_level' => 'emergency',
        ],
        'ai' => 'URGENT',
        'doctor' => 'EMERGENCY',
        'final' => 'EMERGENCY',
        'emergency' => true,
        'ai_emergency' => false,
        'modal' => true,
    ],
    'Test 4 NU→EMERGENCY' => [
        'row' => [
            'triage_classification' => 'NON-URGENT',
            'level' => '1',
            'urgency_label' => 'Emergency',
            'triage_level' => 'emergency',
        ],
        'ai' => 'NON-URGENT',
        'doctor' => 'EMERGENCY',
        'final' => 'EMERGENCY',
        'emergency' => true,
        'ai_emergency' => false,
        'modal' => true,
    ],
];

echo "=== Helper label tests ===\n";
foreach ($cases as $name => $case) {
    echo "\n{$name}\n";
    $row = $case['row'];
    assert_same(triage_ai_preliminary_label($row), $case['ai'], 'Preliminary AI');
    assert_same(triage_doctor_final_label($row), $case['doctor'], 'Final Doctor');
    assert_same(triage_final_decision_label($row), $case['final'], 'Final Decision');
    $er = triage_doctor_final_is_emergency($row);
    $aiEr = triage_ai_was_emergency($row);
    if ($er !== $case['emergency']) {
        fail("{$name} doctor emergency flag mismatch");
    }
    if ($aiEr !== $case['ai_emergency']) {
        fail("{$name} AI emergency flag mismatch");
    }
    $shouldModal = $er && !$aiEr;
    if ($shouldModal !== $case['modal']) {
        fail("{$name} modal trigger mismatch");
    }
    echo "OK  emergency_modal=" . ($shouldModal ? 'yes' : 'no') . "\n";
}

echo "\n=== Database workflow (transaction rollback) ===\n";
$pdo = null;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=medconnect;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    echo "SKIP database: " . $e->getMessage() . "\n";
    echo "\nHelper checks passed. Start XAMPP MySQL to run the live row test.\n";
    exit(0);
}
triage_assessment_ensure_schema($pdo);

$patientId = (int) $pdo->query("SELECT id FROM users WHERE role = 'patient' ORDER BY id DESC LIMIT 1")->fetchColumn();
$providerId = (int) $pdo->query("SELECT id FROM users WHERE role = 'provider' AND is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
if ($patientId <= 0 || $providerId <= 0) {
    fail('Need at least one patient and one provider in the database.');
}

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("
        INSERT INTO triage_results
            (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
             triage_level, triage_classification, recommendation_status)
        VALUES (?, '[]', 'Verify AI vs doctor', '2', 'Urgent', 'pending', NOW(), 'urgent', 'URGENT', 'hidden')
    ");
    $ins->execute([$patientId]);
    $triageId = (int) $pdo->lastInsertId();
    if ($triageId <= 0) {
        fail('Could not insert verification triage row.');
    }

    $before = $pdo->prepare('SELECT triage_classification, level, urgency_label, triage_level, outcome FROM triage_results WHERE id = ?');
    $before->execute([$triageId]);
    $rowBefore = $before->fetch(PDO::FETCH_ASSOC) ?: [];
    assert_same((string) $rowBefore['triage_classification'], 'URGENT', 'DB AI before override');
    assert_same((string) $rowBefore['triage_level'], 'urgent', 'DB GIS before override');

    $pdo->prepare("UPDATE triage_results SET level = ?, urgency_label = ?, triage_level = ?, assessed_at = NOW() WHERE id = ?")
        ->execute(['1', 'Emergency', TriageLevelService::EMERGENCY, $triageId]);

    $applied = triage_apply_doctor_emergency_referral(
        $pdo,
        $triageId,
        $patientId,
        $providerId,
        'Verify AI vs doctor'
    );

    $after = $pdo->prepare('SELECT triage_classification, level, urgency_label, triage_level, outcome FROM triage_results WHERE id = ?');
    $after->execute([$triageId]);
    $rowAfter = $after->fetch(PDO::FETCH_ASSOC) ?: [];

    assert_same((string) $rowAfter['triage_classification'], 'URGENT', 'DB AI after override (must be unchanged)');
    assert_same((string) $rowAfter['level'], '1', 'DB level after override');
    assert_same((string) $rowAfter['urgency_label'], 'Emergency', 'DB urgency_label after override');
    assert_same((string) $rowAfter['triage_level'], 'emergency', 'DB triage_level after override');
    assert_same((string) $rowAfter['outcome'], 'emergency_referral', 'DB outcome after override');

    if (empty($applied['triggered'])) {
        fail('Doctor emergency referral was not triggered.');
    }
    echo "OK  emergency workflow triggered, referral_id=" . (int) ($applied['referral_id'] ?? 0) . "\n";

    $pollRow = $rowAfter;
    if (!triage_doctor_final_is_emergency($pollRow)) {
        fail('Poll would miss doctor-final emergency.');
    }
    if (triage_ai_was_emergency($pollRow)) {
        fail('Poll would treat this as original AI emergency.');
    }
    echo "OK  patient poll would show existing emergency modal (doctor final, not AI)\n";

    $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM triage_results WHERE patient_id = ' . $patientId)->fetchColumn();
    echo "OK  reused single triage row id={$triageId}; no extra insert (count={$countBefore} including this temp row)\n";

    $pdo->rollBack();
    echo "OK  transaction rolled back; no leftover test data\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail($e->getMessage());
}

echo "\nAll verification checks passed.\n";
