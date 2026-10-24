<?php
/**
 * End-to-end verifier for Preliminary AI Assessment read/write/display rules.
 * Uses a rolled-back transaction — no lasting DB writes.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';
require_once BASE_PATH . '/app/includes/patient_symptoms_review_submit.php';
require_once BASE_PATH . '/app/core/TriageLevelService.php';
require_once BASE_PATH . '/app/core/ClinicalInterviewEngine.php';
require_once VIEWS_PATH . '/patient/partials/triage_helpers.php';

function v_fail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function v_ok(string $msg): void
{
    echo "OK  {$msg}\n";
}

function v_assert_same(string $got, string $want, string $label): void
{
    if ($got !== $want) {
        v_fail("{$label}: expected [{$want}] got [{$got}]");
    }
    v_ok("{$label} = {$got}");
}

echo "=== Label / payload fallback tests ===\n";

$labelCases = [
    'EMERGENCY from column' => [
        'row' => ['triage_classification' => 'EMERGENCY', 'assessment_status' => 'COMPLETED'],
        'key' => 'emergency',
        'label' => 'EMERGENCY',
    ],
    'URGENT from provisional payload' => [
        'row' => [
            'triage_classification' => '',
            'assessment_status' => 'COMPLETED',
            'level' => '1',
            'urgency_label' => 'Emergency',
            'triage_level' => 'emergency',
            'assessment_payload' => json_encode([
                'assessment_status' => 'COMPLETED',
                'triage' => ['provisional_engine_classification' => 'URGENT'],
            ]),
        ],
        'key' => 'urgent',
        'label' => 'URGENT',
        'doctor_must_not_equal_ai_source' => true,
    ],
    'NON-URGENT from recommendation_payload' => [
        'row' => [
            'triage_classification' => '',
            'assessment_status' => 'COMPLETED',
            'assessment_payload' => json_encode([
                'triage' => [
                    'recommendation_payload' => ['classification' => 'NON-URGENT'],
                ],
            ]),
        ],
        'key' => 'non_urgent',
        'label' => 'NON-URGENT',
    ],
    'Processing in-progress' => [
        'row' => [
            'triage_classification' => '',
            'assessment_status' => 'IN_PROGRESS',
            'outcome' => 'assessment_in_progress',
            'level' => 'pending',
            'urgency_label' => 'Assessment in progress',
            'assessment_payload' => json_encode([
                'assessment_status' => 'IN_PROGRESS',
                'triage' => ['provisional_engine_classification' => 'NON-URGENT'],
            ]),
        ],
        'key' => 'processing',
        'label' => 'Processing...',
        'doctor' => 'unknown',
    ],
    'Unavailable cancelled empty' => [
        'row' => [
            'triage_classification' => '',
            'assessment_status' => 'CANCELLED',
            'outcome' => 'cancelled',
            'assessment_payload' => json_encode(['triage' => []]),
        ],
        'key' => 'unavailable',
        'label' => 'Unavailable',
        'doctor' => 'unknown',
    ],
    'Unavailable failed empty completed' => [
        'row' => [
            'triage_classification' => '',
            'assessment_status' => 'COMPLETED',
            'assessment_payload' => json_encode(['triage' => ['triage_classification' => '']]),
        ],
        'key' => 'unavailable',
        'label' => 'Unavailable',
    ],
    'Doctor fields never become AI' => [
        'row' => [
            'triage_classification' => '',
            'triage_level' => 'emergency',
            'urgency_label' => 'Emergency',
            'level' => '1',
            'assessment_status' => 'COMPLETED',
            'assessment_payload' => json_encode([
                'triage' => ['triage_classification' => 'NON_URGENT'],
            ]),
        ],
        'key' => 'non_urgent',
        'label' => 'NON-URGENT',
    ],
];

foreach ($labelCases as $name => $case) {
    echo "\n{$name}\n";
    $key = triage_ai_preliminary_key($case['row']);
    $label = triage_ai_preliminary_label($case['row']);
    v_assert_same($key, $case['key'], 'key');
    v_assert_same($label, $case['label'], 'label');
    if (isset($case['doctor'])) {
        v_assert_same(triage_doctor_final_key($case['row']), $case['doctor'], 'doctor key');
    }
    // AI must not equal doctor-overwritten GIS when payload says otherwise.
    if (!empty($case['doctor_must_not_equal_ai_source'])) {
        if ($key === 'emergency') {
            v_fail('AI incorrectly used doctor emergency fields');
        }
        v_ok('AI ignored doctor emergency fields');
    }
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    require_once BASE_PATH . '/config/db.php';
    $pdo = $GLOBALS['pdo'] ?? null;
}
if (!$pdo instanceof PDO) {
    echo "\nSKIP DB: no PDO\n";
    exit(0);
}

echo "\n=== DB persist + refresh + filter tests (rollback) ===\n";
triage_assessment_ensure_schema($pdo);

$patientId = (int) ($pdo->query("SELECT id FROM users WHERE role='patient' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
if ($patientId <= 0) {
    $pdo->exec("INSERT INTO users (first_name, last_name, email, password, role, is_active) VALUES ('AI','Verify','ai.verify.temp@medconnect.local','x','patient',1)");
    $patientId = (int) $pdo->lastInsertId();
}

$pdo->beginTransaction();
try {
    $classes = [
        'EMERGENCY' => [
            'assessment' => [
                'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
                'engine' => 'verify',
                'confidence' => ['score' => 90],
                'severity' => ['severity' => 'severe'],
                'recommendations' => ['Seek emergency care'],
                'detected_symptoms' => [],
                'possible_conditions' => [],
                'triage' => [
                    'triage_classification' => '',
                    'provisional_engine_classification' => 'EMERGENCY',
                    'recommendation_payload' => ['classification' => 'EMERGENCY'],
                    'db_level' => '1',
                    'urgency_label' => 'Emergency (Immediate)',
                ],
                'db_level' => '1',
                'urgency_label' => 'Emergency (Immediate)',
            ],
            'want' => 'EMERGENCY',
            'wantKey' => 'emergency',
            'complaint' => 'Verify AI EMERGENCY chest pain severe',
        ],
        'URGENT' => [
            'assessment' => [
                'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
                'engine' => 'verify',
                'confidence' => ['score' => 80],
                'severity' => ['severity' => 'moderate'],
                'recommendations' => ['Book soon'],
                'detected_symptoms' => [],
                'possible_conditions' => [],
                'triage' => [
                    'triage_classification' => '',
                    'provisional_engine_classification' => 'URGENT',
                    'db_level' => '2',
                    'urgency_label' => 'Urgent (Priority)',
                ],
                'db_level' => '2',
                'urgency_label' => 'Urgent (Priority)',
            ],
            'want' => 'URGENT',
            'wantKey' => 'urgent',
            'complaint' => 'Verify AI URGENT high fever',
        ],
        'NON-URGENT' => [
            'assessment' => [
                'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
                'engine' => 'verify',
                'confidence' => ['score' => 70],
                'severity' => ['severity' => 'mild'],
                'recommendations' => ['Home care'],
                'detected_symptoms' => [],
                'possible_conditions' => [],
                'triage' => [
                    'triage_classification' => '',
                    'recommendation_payload' => ['classification' => 'NON-URGENT'],
                    'db_level' => '3',
                    'urgency_label' => 'Non-Urgent (Routine)',
                ],
                'db_level' => '3',
                'urgency_label' => 'Non-Urgent (Routine)',
            ],
            'want' => 'NON-URGENT',
            'wantKey' => 'non_urgent',
            'complaint' => 'Verify AI NON-URGENT mild cough',
        ],
    ];

    $ids = [];
    foreach ($classes as $name => $case) {
        $id = patient_symptoms_review_bind_assessment_row(
            $pdo,
            $patientId,
            0,
            $case['complaint'],
            ['verify'],
            $case['assessment']
        );
        if ($id <= 0) {
            v_fail("bind failed for {$name}");
        }
        $ids[$name] = $id;

        $stmt = $pdo->prepare('
            SELECT id, patient_id, chief_complaint, triage_classification, assessment_status,
                   assessment_payload, level, urgency_label, triage_level, outcome
            FROM triage_results WHERE id = ? AND patient_id = ? LIMIT 1
        ');
        $stmt->execute([$id, $patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$row) {
            v_fail("row missing after bind {$name}");
        }

        $col = strtoupper(str_replace('-', '_', trim((string) ($row['triage_classification'] ?? ''))));
        $wantCol = strtoupper(str_replace('-', '_', $case['want']));
        if ($col !== $wantCol && $col !== str_replace('_', '', $wantCol)) {
            // Accept EMERGENCY / NON_URGENT forms
            if ($col !== 'EMERGENCY' && $col !== 'URGENT' && $col !== 'NON_URGENT' && $col !== 'NONURGENT') {
                v_fail("{$name} column not persisted: [{$col}]");
            }
        }
        v_assert_same(triage_ai_preliminary_key($row), $case['wantKey'], "{$name} AI key after save");
        v_assert_same(triage_ai_preliminary_label($row), $case['want'], "{$name} AI label after save");
        v_assert_same((string) ($row['chief_complaint'] ?? ''), $case['complaint'], "{$name} complaint link");

        // Simulate refresh: clear in-memory, re-read
        $stmt->execute([$id, $patientId]);
        $refreshed = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        v_assert_same(triage_ai_preliminary_label($refreshed), $case['want'], "{$name} persists after refresh");

        // Doctor fields alone must not replace AI if we wipe AI column but keep payload
        $payloadOnly = $refreshed;
        $payloadOnly['triage_classification'] = '';
        $payloadOnly['triage_level'] = 'emergency';
        $payloadOnly['urgency_label'] = 'Emergency';
        $payloadOnly['level'] = '1';
        $still = triage_ai_preliminary_key($payloadOnly);
        if ($still === 'emergency' && $case['wantKey'] !== 'emergency') {
            v_fail("{$name}: doctor fields leaked into AI after column wipe");
        }
        v_assert_same($still, $case['wantKey'], "{$name} payload fallback after column wipe");
    }

    // In-progress retained
    $progressId = patient_symptoms_review_bind_assessment_row(
        $pdo,
        $patientId,
        0,
        'Verify AI IN_PROGRESS headache',
        ['verify'],
        [
            'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
            'engine' => 'verify',
            'confidence' => ['score' => 0],
            'severity' => ['severity' => ''],
            'recommendations' => [],
            'detected_symptoms' => [],
            'possible_conditions' => [],
            'followup_required' => true,
            'triage' => [
                'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
                'provisional_engine_classification' => 'NON-URGENT',
                'triage_classification' => '',
                'db_level' => 'pending',
                'urgency_label' => 'Assessment in progress',
            ],
            'db_level' => 'pending',
            'urgency_label' => 'Assessment in progress',
        ]
    );
    $pStmt = $pdo->prepare('SELECT * FROM triage_results WHERE id = ? LIMIT 1');
    $pStmt->execute([$progressId]);
    $progressRow = $pStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    v_assert_same(triage_ai_preliminary_key($progressRow), 'processing', 'IN_PROGRESS key');
    v_assert_same(triage_ai_preliminary_label($progressRow), 'Processing...', 'IN_PROGRESS label');

    // Cancelled stub
    $cancelId = patient_symptoms_review_bind_assessment_row(
        $pdo,
        $patientId,
        0,
        'Verify AI CANCELLED stub',
        ['verify'],
        [
            'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
            'engine' => 'verify',
            'confidence' => ['score' => 0],
            'severity' => ['severity' => ''],
            'recommendations' => [],
            'detected_symptoms' => [],
            'possible_conditions' => [],
            'triage' => [
                'assessment_status' => ClinicalInterviewEngine::STATUS_IN_PROGRESS,
                'triage_classification' => '',
            ],
        ]
    );
    $pdo->prepare("UPDATE triage_results SET assessment_status='CANCELLED', outcome='cancelled' WHERE id=?")
        ->execute([$cancelId]);
    $cStmt = $pdo->prepare('SELECT * FROM triage_results WHERE id = ? LIMIT 1');
    $cStmt->execute([$cancelId]);
    $cancelRow = $cStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    v_assert_same(triage_ai_preliminary_label($cancelRow), 'Unavailable', 'cancelled empty label');

    // Health Summary filter: exclude cancelled, keep in-progress + completed
    $hist = $pdo->prepare("
        SELECT id, assessment_status, outcome, chief_complaint, triage_classification, assessment_payload
        FROM triage_results
        WHERE patient_id = ? AND id IN (?, ?, ?, ?, ?)
        ORDER BY id DESC
    ");
    $hist->execute([$patientId, $ids['EMERGENCY'], $ids['URGENT'], $ids['NON-URGENT'], $progressId, $cancelId]);
    $rows = $hist->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $consultationIds = [];
    $filtered = array_values(array_filter(
        $rows,
        static function (array $row) use ($consultationIds): bool {
            $id = (int) ($row['id'] ?? 0);
            if (!mc_patient_visit_history_is_unfinished_attempt($row)) {
                return true;
            }
            $status = strtoupper(trim((string) ($row['assessment_status'] ?? '')));
            $outcome = strtolower(trim((string) ($row['outcome'] ?? '')));
            if ($status === 'IN_PROGRESS' || $outcome === 'assessment_in_progress') {
                return true;
            }
            return $id > 0 && isset($consultationIds[$id]);
        }
    ));
    $filteredIds = array_map(static fn ($r) => (int) $r['id'], $filtered);
    if (in_array($cancelId, $filteredIds, true)) {
        v_fail('cancelled assessment was not excluded from history filter');
    }
    v_ok('cancelled excluded from history filter');
    if (!in_array($progressId, $filteredIds, true)) {
        v_fail('in-progress assessment was not retained');
    }
    v_ok('in-progress retained in history filter');
    foreach ($ids as $name => $id) {
        if (!in_array($id, $filteredIds, true)) {
            v_fail("completed {$name} missing from filtered history");
        }
    }
    v_ok('all three completed classifications retained');

    // Failed AI: empty completed → Unavailable
    $failId = patient_symptoms_review_bind_assessment_row(
        $pdo,
        $patientId,
        0,
        'Verify AI FAILED empty',
        ['verify'],
        [
            'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
            'engine' => 'verify',
            'confidence' => ['score' => 0],
            'severity' => ['severity' => ''],
            'recommendations' => [],
            'detected_symptoms' => [],
            'possible_conditions' => [],
            'triage' => [
                'assessment_status' => ClinicalInterviewEngine::STATUS_COMPLETED,
                'triage_classification' => '',
                'db_level' => '3',
                'urgency_label' => 'Non-Urgent',
            ],
            'db_level' => '3',
            'urgency_label' => 'Non-Urgent',
        ]
    );
    // Force-empty classification column to simulate failed persist with no payload class
    $pdo->prepare("UPDATE triage_results SET triage_classification=NULL, assessment_payload=? WHERE id=?")
        ->execute([json_encode(['assessment_status' => 'COMPLETED', 'triage' => []]), $failId]);
    $fStmt = $pdo->prepare('SELECT * FROM triage_results WHERE id = ? LIMIT 1');
    $fStmt->execute([$failId]);
    $failRow = $fStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    v_assert_same(triage_ai_preliminary_label($failRow), 'Unavailable', 'failed AI empty result');

    echo "\nAll preliminary AI flow checks passed.\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
        v_ok('transaction rolled back');
    }
}
