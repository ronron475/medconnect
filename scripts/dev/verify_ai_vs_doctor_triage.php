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

$pdo->beginTransaction();
try {
    $patientId = (int) $pdo->query("SELECT id FROM users WHERE role = 'patient' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $providerId = (int) $pdo->query("SELECT id FROM users WHERE role = 'provider' AND is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($patientId <= 0) {
        $pdo->prepare("
            INSERT INTO users (first_name, last_name, email, password, role, is_active, is_email_verified)
            VALUES ('Verify', 'Patient', 'verify-ai-doctor@medconnect.local', 'x', 'patient', 1, 1)
        ")->execute();
        $patientId = (int) $pdo->lastInsertId();
    }
    if ($providerId <= 0) {
        fail('Need at least one provider in the database.');
    }

    $insert = $pdo->prepare("
        INSERT INTO triage_results
            (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
             triage_level, triage_classification, recommendation_status)
        VALUES (?, '[]', ?, ?, ?, 'pending', NOW(), ?, ?, 'hidden')
    ");

    $scenarios = [
        ['name' => 'DB Test 1 NU→NU', 'ai' => 'NON-URGENT', 'ai_level' => '3', 'ai_gis' => 'non_urgent', 'ai_label' => 'Non-Urgent', 'to' => '3', 'to_label' => 'Non-Urgent', 'to_gis' => 'non_urgent', 'expect_er' => false],
        ['name' => 'DB Test 2 URGENT→NU', 'ai' => 'URGENT', 'ai_level' => '2', 'ai_gis' => 'urgent', 'ai_label' => 'Urgent', 'to' => '3', 'to_label' => 'Non-Urgent', 'to_gis' => 'non_urgent', 'expect_er' => false],
        ['name' => 'DB Test 3 URGENT→EMERGENCY', 'ai' => 'URGENT', 'ai_level' => '2', 'ai_gis' => 'urgent', 'ai_label' => 'Urgent', 'to' => '1', 'to_label' => 'Emergency', 'to_gis' => 'emergency', 'expect_er' => true],
        ['name' => 'DB Test 4 NU→EMERGENCY', 'ai' => 'NON-URGENT', 'ai_level' => '3', 'ai_gis' => 'non_urgent', 'ai_label' => 'Non-Urgent', 'to' => '1', 'to_label' => 'Emergency', 'to_gis' => 'emergency', 'expect_er' => true],
    ];

    foreach ($scenarios as $sc) {
        echo "\n{$sc['name']}\n";
        $insert->execute([$patientId, $sc['name'], $sc['ai_level'], $sc['ai_label'], $sc['ai_gis'], $sc['ai']]);
        $triageId = (int) $pdo->lastInsertId();
        if ($triageId <= 0) {
            fail('Could not insert verification triage row.');
        }

        $pdo->prepare("UPDATE triage_results SET level = ?, urgency_label = ?, triage_level = ?, assessed_at = NOW() WHERE id = ?")
            ->execute([$sc['to'], $sc['to_label'], $sc['to_gis'], $triageId]);

        $applied = ['triggered' => false, 'referral_id' => 0];
        if ($sc['to_gis'] === 'emergency') {
            $applied = triage_apply_doctor_emergency_referral(
                $pdo,
                $triageId,
                $patientId,
                $providerId,
                $sc['name']
            );
        }

        $afterStmt = $pdo->prepare('SELECT triage_classification, level, urgency_label, triage_level, outcome FROM triage_results WHERE id = ?');
        $afterStmt->execute([$triageId]);
        $rowAfter = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        assert_same((string) $rowAfter['triage_classification'], $sc['ai'], 'AI preliminary preserved');
        assert_same((string) $rowAfter['level'], $sc['to'], 'Doctor level');
        assert_same((string) $rowAfter['urgency_label'], $sc['to_label'], 'Doctor urgency_label');
        assert_same((string) $rowAfter['triage_level'], $sc['to_gis'], 'Doctor triage_level');

        $labels = [
            'ai' => triage_ai_preliminary_label($rowAfter),
            'doctor' => triage_doctor_final_label($rowAfter),
            'final' => triage_final_decision_label($rowAfter),
        ];
        echo "    display AI={$labels['ai']} Doctor={$labels['doctor']} Final={$labels['final']}\n";

        $er = triage_doctor_final_is_emergency($rowAfter);
        $aiEr = triage_ai_was_emergency($rowAfter);
        $modal = $er && !$aiEr;
        if ($sc['expect_er']) {
            if (!$er || $aiEr || empty($applied['triggered'])) {
                fail('Expected doctor-final emergency referral + patient modal.');
            }
            if ((string) $rowAfter['outcome'] !== 'emergency_referral') {
                fail('Expected outcome=emergency_referral');
            }
            echo "OK  emergency referral triggered, modal would appear, referral_id=" . (int) ($applied['referral_id'] ?? 0) . "\n";
        } else {
            if ($modal || !empty($applied['triggered']) || $er) {
                fail('Did not expect emergency referral/modal.');
            }
            echo "OK  no emergency referral / no modal\n";
        }
    }

    $pdo->rollBack();
    echo "\nOK  transaction rolled back; no leftover test data\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail($e->getMessage());
}

echo "\nAll verification checks passed.\n";
