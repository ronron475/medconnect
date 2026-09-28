<?php
/**
 * BHW clinical data is visible to a doctor only when that doctor has a consultation
 * with the same patient. Assignment, another patient's consultation, and an active
 * account do not grant access. Admin profile view is not passed through this gate.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/app/includes/consultation_recorded_data.php';
require_once $root . '/app/includes/provider_patient_access.php';
require_once $root . '/app/includes/community_bhw_activity.php';
require_once $root . '/app/includes/patient_external_healthcare_visits.php';
require_once $root . '/app/includes/patient_health_summary.php';
require_once $root . '/app/includes/patient_settings.php';

$pass = 0;
$fail = 0;

function check(bool $ok, string $label): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$label}\n";
}

patient_settings_ensure_schema($pdo);
consultation_recorded_data_ensure_schema($pdo);

$marker = 'bhwacc_' . bin2hex(random_bytes(3));
$userIds = [];
$consultIds = [];
$recordedIds = [];
$regIds = [];
$triageIds = [];
$slotIds = [];

try {
    $insertUser = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())');
    foreach ([['patient', 'A'], ['patient', 'C'], ['provider', 'DA'], ['provider', 'DB'], ['bhw', 'H']] as $user) {
        $insertUser->execute([
            'T',
            $user[1],
            $marker . '_' . strtolower($user[1]) . '@t.test',
            password_hash('x', PASSWORD_DEFAULT),
            $user[0],
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }
    [$patientA, $patientC, $doctorA, $doctorB, $bhwId] = $userIds;

    $emailA = $marker . '_a@t.test';
    $regCols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $regInsert = [
        'email' => $emailA,
        'user_id' => $patientA,
        'first_name' => 'T',
        'last_name' => 'A',
        'blood_type' => 'O+',
        'allergies' => $marker . '_allergy',
        'existing_conditions' => $marker . '_condition',
        'current_medications' => $marker . '_med',
        'contact_number' => '09171234567',
        'barangay' => 'Lag-asan',
        'medical_profile_updated_by' => $bhwId,
        'medical_profile_updated_at' => date('Y-m-d H:i:s'),
        'status' => 'verified',
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $colNames = [];
    $colVals = [];
    $available = [];
    foreach ($regCols as $col) {
        $available[(string) $col['Field']] = $col;
    }
    foreach ($regInsert as $name => $value) {
        if (!isset($available[$name])) {
            continue;
        }
        $colNames[] = $name;
        $colVals[] = $value;
    }
    foreach ($available as $name => $col) {
        if (in_array($name, $colNames, true)) {
            continue;
        }
        $nullable = strtoupper((string) ($col['Null'] ?? '')) === 'YES';
        $default = $col['Default'] ?? null;
        $extra = strtolower((string) ($col['Extra'] ?? ''));
        if ($nullable || $default !== null || str_contains($extra, 'auto_increment')) {
            continue;
        }
        $type = strtolower((string) ($col['Type'] ?? ''));
        $colNames[] = $name;
        if (str_contains($type, 'int')) {
            $colVals[] = 0;
        } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
            $colVals[] = date('Y-m-d H:i:s');
        } else {
            $colVals[] = '';
        }
    }
    $placeholders = implode(',', array_fill(0, count($colNames), '?'));
    $pdo->prepare('INSERT INTO patient_registrations (' . implode(',', $colNames) . ') VALUES (' . $placeholders . ')')
        ->execute($colVals);
    $regIds[] = (int) $pdo->lastInsertId();

    $saved = consultation_recorded_data_save($pdo, $patientA, 0, $bhwId, 'bhw', [
        'temperature_c' => 37.4,
        'chief_complaint' => $marker . '_complaint',
    ], null, false);
    check(!empty($saved['success']), '1. BHW save of pre-consultation clinical data succeeds');
    $recordedId = (int) ($saved['id'] ?? 0);
    if ($recordedId > 0) {
        $recordedIds[] = $recordedId;
    }
    $stored = $pdo->prepare('SELECT recorded_by, recorder_role, recorded_at FROM consultation_recorded_data WHERE id = ?');
    $stored->execute([$recordedId]);
    $storedRow = $stored->fetch(PDO::FETCH_ASSOC) ?: [];
    check((int) ($storedRow['recorded_by'] ?? 0) === $bhwId, '1. saved recorded_by is the BHW');
    check((string) ($storedRow['recorder_role'] ?? '') === 'bhw', '1. saved recorder_role is bhw');
    check(trim((string) ($storedRow['recorded_at'] ?? '')) !== '', '1. saved recorded_at is set');
    check(patient_clinical_profile_authored_by_bhw($pdo, $patientA), '1. profile author is the BHW');

    $insertConsult = $pdo->prepare("INSERT INTO consultations (patient_id, provider_id, provider_name, consult_date, consult_time, consult_type, status, created_at) VALUES (?,?,?,CURDATE(),CURTIME(),'General Consultation',?,NOW())");
    $insertConsult->execute([$patientA, $doctorA, 'Doctor A', 'completed']);
    $consultA = (int) $pdo->lastInsertId();
    $consultIds[] = $consultA;
    consultation_recorded_data_attach_pending_to_consultation($pdo, $patientA, $consultA);

    $insertConsult->execute([$patientC, $doctorB, 'Doctor B', 'completed']);
    $consultC = (int) $pdo->lastInsertId();
    $consultIds[] = $consultC;

    check(provider_may_view_bhw_clinical_data($pdo, $doctorA, $patientA), '2. Doctor A with a consultation may view BHW data');
    $activityA = community_bhw_activity_load_for_provider($pdo, $doctorA, $patientA);
    $joinedA = json_encode($activityA);
    check(is_string($joinedA) && str_contains($joinedA, $marker . '_complaint'), '2. Doctor A activity includes the BHW complaint');
    $redactedA = provider_redact_bhw_clinical_profile($pdo, $doctorA, $patientA, [
        'allergies' => $marker . '_allergy',
        'history' => $marker . '_condition',
        'medications' => $marker . '_med',
        'blood_type' => 'O+',
    ]);
    check(($redactedA['allergies'] ?? '') === $marker . '_allergy', '2. Doctor A still sees the BHW-authored allergy');
    check(empty($redactedA['_bhw_clinical_hidden']), '2. Doctor A profile is not hidden');
    $owned = consultation_recorded_data_for_authorized_provider($pdo, $doctorA, $consultA, $patientA);
    check(!empty($owned['allowed']), '2. Doctor A API allows recorded data on their consultation');
    $ownedJson = json_encode($owned['recorded'] ?? []);
    check(is_string($ownedJson) && str_contains($ownedJson, '37.4'), '2. Doctor A API returns the BHW temperature');

    check(!provider_may_view_bhw_clinical_data($pdo, $doctorB, $patientA), '3. Doctor B with no consultation cannot view BHW data');
    $activityB = community_bhw_activity_load_for_provider($pdo, $doctorB, $patientA);
    check((int) ($activityB['total'] ?? -1) === 0, '3. Doctor B activity payload is empty');
    $redactedB = provider_redact_bhw_clinical_profile($pdo, $doctorB, $patientA, [
        'allergies' => $marker . '_allergy',
        'history' => $marker . '_condition',
        'medications' => $marker . '_med',
        'blood_type' => 'O+',
        'contact' => '09171234567',
    ]);
    check(($redactedB['allergies'] ?? 'x') === '', '3. Doctor B allergy field is removed');
    check(($redactedB['history'] ?? 'x') === '', '3. Doctor B condition field is removed');
    check(($redactedB['medications'] ?? 'x') === '', '3. Doctor B medication field is removed');
    check(($redactedB['blood_type'] ?? 'x') === '', '3. Doctor B blood type is removed');
    check(($redactedB['contact'] ?? '') === '09171234567', '3. Doctor B still receives non-clinical contact');
    check(!empty($redactedB['_bhw_clinical_hidden']), '3. Doctor B profile is marked hidden');
    $visitsB = patient_external_healthcare_visits_for_authorized_provider($pdo, $doctorB, $patientA, 0);
    check(empty($visitsB['allowed']), '3. Doctor B external-visit API is denied');

    $sessionStmt = $pdo->prepare('SELECT id FROM consultations WHERE id = ? AND provider_id = ? LIMIT 1');
    $sessionStmt->execute([$consultA, $doctorB]);
    check(!$sessionStmt->fetchColumn(), '4. Doctor B cannot open Doctor A consultation by id');
    $cross = consultation_recorded_data_for_authorized_provider($pdo, $doctorB, $consultA, $patientA);
    check(empty($cross['allowed']), '4. Doctor B recorded-data API denied for Doctor A consultation');
    $otherPatient = consultation_recorded_data_for_authorized_provider($pdo, $doctorB, $consultC, $patientA);
    check(empty($otherPatient['allowed']), '4. Doctor B consultation with Patient C does not open Patient A');
    check(!provider_may_view_bhw_clinical_data($pdo, $doctorB, $patientA), '4. Patient C consultation does not grant Patient A BHW access');
    check(!provider_may_view_patient_document($pdo, $doctorB, $patientA, 'bhw_' . $patientA . '_note.pdf'), '4. Doctor B cannot view a BHW document');
    check(provider_may_view_patient_document($pdo, $doctorA, $patientA, 'bhw_' . $patientA . '_note.pdf'), '4. Doctor A can view a BHW document');
    check(provider_may_view_patient_document($pdo, $doctorB, $patientA, 'residency_id.pdf'), '4. Non-BHW document flag stays with the existing page check');
    $directory = provider_patient_strip_hidden_bhw_clinical($pdo, $doctorB, [[
        'id' => $patientA,
        'name' => 'T A',
        'allergies' => $marker . '_allergy',
        'history' => $marker . '_condition',
        'medications' => $marker . '_med',
        'blood_type' => 'O+',
    ]]);
    check(($directory[0]['allergies'] ?? 'x') === '' && ($directory[0]['name'] ?? '') === 'T A', '4. Caseload row hides BHW clinical fields and keeps the name');
    $directoryA = provider_patient_strip_hidden_bhw_clinical($pdo, $doctorA, [[
        'id' => $patientA,
        'allergies' => $marker . '_allergy',
    ]]);
    check(($directoryA[0]['allergies'] ?? '') === $marker . '_allergy', '2. Caseload row keeps BHW clinical fields for Doctor A');

    $activeStillDenied = !provider_may_view_bhw_clinical_data($pdo, $doctorB, $patientA);
    check($activeStillDenied, '4. Active Doctor B account does not grant access');

    $assigned = false;
    try {
        $triageCols = $pdo->query('SHOW COLUMNS FROM triage_results')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('assigned_provider_id', $triageCols, true) && in_array('recommendation_status', $triageCols, true)) {
            $pdo->prepare("
                INSERT INTO triage_results
                    (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at, recommendation_status, assigned_provider_id)
                VALUES (?, '[]', ?, '3', 'Non-Urgent', 'pending', NOW(), 'pending_approval', ?)
            ")->execute([$patientA, $marker . '_triage', $doctorB]);
            $triageIds[] = (int) $pdo->lastInsertId();
            $assignStmt = $pdo->prepare("
                SELECT id
                FROM triage_results
                WHERE patient_id = ?
                  AND assigned_provider_id = ?
                  AND recommendation_status = 'pending_approval'
                  AND assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                LIMIT 1
            ");
            $assignStmt->execute([$patientA, $doctorB]);
            $assigned = (bool) $assignStmt->fetchColumn()
                && !provider_may_view_bhw_clinical_data($pdo, $doctorB, $patientA);
        }
    } catch (Throwable $e) {
        $assigned = false;
        echo 'WARN  triage assignment setup: ' . $e->getMessage() . "\n";
    }
    check($assigned, '4. Triage assignment does not reveal BHW clinical data');

    $booked = false;
    try {
        $pdo->prepare("
            INSERT INTO appointment_slots (provider_id, patient_id, slot_date, start_time, end_time, status)
            VALUES (?, ?, CURDATE(), '09:00:00', '09:30:00', 'booked')
        ")->execute([$doctorB, $patientA]);
        $slotIds[] = (int) $pdo->lastInsertId();
        $slotStmt = $pdo->prepare("
            SELECT id
            FROM appointment_slots
            WHERE provider_id = ? AND patient_id = ? AND status = 'booked'
              AND slot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            LIMIT 1
        ");
        $slotStmt->execute([$doctorB, $patientA]);
        $booked = (bool) $slotStmt->fetchColumn()
            && !provider_may_view_bhw_clinical_data($pdo, $doctorB, $patientA);
    } catch (Throwable $e) {
        $booked = false;
        echo 'WARN  slot setup: ' . $e->getMessage() . "\n";
    }
    check($booked, '4. Booked slot without a consultation does not reveal BHW clinical data');

    $adminView = (string) file_get_contents($root . '/resources/views/admin/bhw_patient_update_view.php');
    check(!str_contains($adminView, 'provider_redact_bhw_clinical_profile'), '5. Admin BHW profile view is not doctor-redacted');
    check(str_contains($adminView, 'pr.allergies'), '5. Admin view still loads clinical profile fields');
    $superBridge = (string) file_get_contents($root . '/resources/views/superadmin/bhw_patient_update_view.php');
    check(str_contains($superBridge, '_module.php'), '5. SuperAdmin view still uses the shared admin module');

    $kept = patient_health_summary_provider_update($pdo, $patientA, $doctorB, [
        'blood_type' => '',
        'allergies' => '',
        'existing_conditions' => '',
        'current_medications' => '',
    ], null);
    check($kept, '3. Empty update from Doctor B does not fail');
    $after = patient_clinical_profile_values($pdo, $patientA);
    check(($after['allergies'] ?? '') === $marker . '_allergy', '3. Empty update does not expose by wiping or rewriting the BHW allergy');
    check(patient_clinical_profile_authored_by_bhw($pdo, $patientA), '3. Empty update leaves the BHW as author');

    $ownNote = $pdo->prepare("INSERT INTO clinical_notes (consultation_id, patient_id, provider_id, diagnosis, created_at) VALUES (?,?,?,?,NOW())");
    $noteOk = true;
    try {
        $ownNote->execute([$consultA, $patientA, $doctorA, $marker . '_dx']);
    } catch (Throwable $e) {
        $noteOk = false;
        echo 'WARN  clinical note: ' . $e->getMessage() . "\n";
    }
    if ($noteOk) {
        $note = $pdo->prepare('SELECT diagnosis FROM clinical_notes WHERE consultation_id = ? AND provider_id = ? LIMIT 1');
        $note->execute([$consultA, $doctorA]);
        check((string) ($note->fetchColumn() ?: '') === $marker . '_dx', '6. Doctor A still has their own consultation note');
        $foreign = $pdo->prepare('SELECT id FROM clinical_notes WHERE consultation_id = ? AND provider_id = ? LIMIT 1');
        $foreign->execute([$consultA, $doctorB]);
        check(!$foreign->fetchColumn(), '6. Doctor B does not receive Doctor A consultation note');
    } else {
        check(false, '6. Doctor A still has their own consultation note');
        check(false, '6. Doctor B does not receive Doctor A consultation note');
    }
} catch (Throwable $e) {
    check(false, 'threw: ' . $e->getMessage());
} finally {
    try {
        if ($recordedIds !== []) {
            $pdo->exec('DELETE FROM consultation_recorded_data WHERE id IN (' . implode(',', array_map('intval', $recordedIds)) . ')');
        }
        if ($consultIds !== []) {
            $pdo->exec('DELETE FROM consultation_recorded_data WHERE consultation_id IN (' . implode(',', array_map('intval', $consultIds)) . ')');
            $pdo->exec('DELETE FROM clinical_notes WHERE consultation_id IN (' . implode(',', array_map('intval', $consultIds)) . ')');
            $pdo->exec('DELETE FROM consultations WHERE id IN (' . implode(',', array_map('intval', $consultIds)) . ')');
        }
        if ($triageIds !== []) {
            $pdo->exec('DELETE FROM triage_results WHERE id IN (' . implode(',', array_map('intval', $triageIds)) . ')');
        }
        if ($slotIds !== []) {
            $pdo->exec('DELETE FROM appointment_slots WHERE id IN (' . implode(',', array_map('intval', $slotIds)) . ')');
        }
        if ($userIds !== []) {
            $idList = implode(',', array_map('intval', $userIds));
            $pdo->exec('DELETE FROM consultation_recorded_data WHERE patient_id IN (' . $idList . ') OR recorded_by IN (' . $idList . ')');
            $pdo->exec('DELETE FROM patient_registrations WHERE user_id IN (' . $idList . ') OR email LIKE ' . $pdo->quote($marker . '%'));
            try {
                $pdo->exec('DELETE FROM patient_audit_logs WHERE patient_id IN (' . $idList . ')');
            } catch (Throwable $e) {
            }
            try {
                $pdo->exec("DELETE FROM notifications WHERE related_id IN ({$idList}) OR user_id IN ({$idList})");
            } catch (Throwable $e) {
            }
            $pdo->exec('DELETE FROM users WHERE id IN (' . $idList . ')');
        }
        if ($regIds !== []) {
            $pdo->exec('DELETE FROM patient_registrations WHERE id IN (' . implode(',', array_map('intval', $regIds)) . ')');
        }
    } catch (Throwable $e) {
        echo 'WARN  cleanup: ' . $e->getMessage() . "\n";
    }
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
