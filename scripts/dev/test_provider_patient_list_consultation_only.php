<?php
/**
 * A doctor's Patient List and medical-record view include a patient only when
 * consultations.provider_id matches that doctor. Slots, triage assignment,
 * referrals, and health-summary requests stay out of the list. Those workflows
 * still authorize through provider_patient_assert_access.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/app/includes/provider_patient_access.php';
require_once $root . '/app/core/GisDashboardService.php';

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

function listed(PDO $pdo, int $doctorId, int $patientId): bool
{
    foreach (provider_patient_caseload_directory($pdo, $doctorId) as $row) {
        if ((int) ($row['id'] ?? 0) === $patientId) {
            return true;
        }
    }

    return false;
}

function gisListed(GisDashboardService $gis, int $doctorId, int $patientId): bool
{
    $method = new ReflectionMethod(GisDashboardService::class, 'applyProviderCaseloadFilter');
    $method->setAccessible(true);
    $where = ["u.role = 'patient'", 'u.id = ?'];
    $params = [$patientId];
    $method->invokeArgs($gis, [&$where, &$params, $doctorId]);
    $sql = 'SELECT u.id FROM users u WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
    $stmt = gisPdo($gis)->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

function gisPdo(GisDashboardService $gis): PDO
{
    $prop = new ReflectionProperty(GisDashboardService::class, 'pdo');
    $prop->setAccessible(true);
    $pdo = $prop->getValue($gis);
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('GIS service has no PDO.');
    }

    return $pdo;
}

$marker = 'plistfix_' . bin2hex(random_bytes(3));
$userIds = [];
$consultIds = [];
$slotIds = [];
$triageIds = [];
$referralIds = [];
$requestIds = [];

$accessSrc = (string) file_get_contents($root . '/app/includes/provider_patient_access.php');
$recordsSrc = (string) file_get_contents($root . '/resources/views/provider/medical_records.php');
$gisSrc = (string) file_get_contents($root . '/app/core/GisDashboardService.php');
$historySrc = (string) file_get_contents($root . '/app/includes/provider_consultation_history.php');
$referralApi = (string) file_get_contents($root . '/app/api/provider/create_referral.php');
$triageApi = (string) file_get_contents($root . '/app/api/provider/update_triage.php');
$summaryApi = (string) file_get_contents($root . '/app/api/provider/update_patient_medical_profile.php');

try {
    $insertUser = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())');
    foreach ([['patient', 'A'], ['provider', 'DA'], ['provider', 'DB']] as $user) {
        $insertUser->execute([
            'T',
            $user[1],
            $marker . '_' . strtolower($user[1]) . '@t.test',
            password_hash('x', PASSWORD_DEFAULT),
            $user[0],
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }
    [$patientA, $doctorA, $doctorB] = $userIds;

    $insertConsult = $pdo->prepare('
        INSERT INTO consultations (patient_id, provider_id, provider_name, consult_date, consult_time, consult_type, status, created_at)
        VALUES (?, ?, ?, CURDATE(), ?, ?, ?, NOW())
    ');
    $insertConsult->execute([$patientA, $doctorA, 'Doctor A', '09:00:00', 'General Consultation', 'completed']);
    $consultIds[] = (int) $pdo->lastInsertId();

    $gis = (new ReflectionClass(GisDashboardService::class))->newInstanceWithoutConstructor();
    $gisPdoProp = new ReflectionProperty(GisDashboardService::class, 'pdo');
    $gisPdoProp->setAccessible(true);
    $gisPdoProp->setValue($gis, $pdo);

    check(listed($pdo, $doctorA, $patientA), '1. Doctor A list shows Patient A after a consultation');
    check(!listed($pdo, $doctorB, $patientA), '2. Doctor B list hides Patient A with no consultation');
    check(gisListed($gis, $doctorA, $patientA), '9. Doctor A GIS patient API still returns Patient A');
    check(!gisListed($gis, $doctorB, $patientA), '8. Doctor B GIS patient API hides Patient A');

    $insertSlot = $pdo->prepare("
        INSERT INTO appointment_slots (provider_id, patient_id, slot_date, start_time, end_time, status)
        VALUES (?, ?, CURDATE(), '23:11:00', '23:41:00', 'booked')
    ");
    $insertSlot->execute([$doctorB, $patientA]);
    $slotIds[] = (int) $pdo->lastInsertId();
    $slotAccess = provider_patient_assert_access($pdo, $doctorB, $patientA, 0);
    check(!listed($pdo, $doctorB, $patientA), '3. Booking alone does not add Patient A to Doctor B');
    check(!empty($slotAccess['allowed']), '10a. Booked slot still passes workflow access');
    check(!provider_patient_has_consultation_history($pdo, $doctorB, $patientA), '7. Medical Records URL gate denies Doctor B without a consultation');
    $pdo->prepare('DELETE FROM appointment_slots WHERE id = ?')->execute([$slotIds[0]]);

    $insertTriage = $pdo->prepare("
        INSERT INTO triage_results
            (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at, recommendation_status, assigned_provider_id)
        VALUES (?, 'cough', 'cough', 'routine', 'Routine', 'pending', NOW(), 'pending_approval', ?)
    ");
    $insertTriage->execute([$patientA, $doctorB]);
    $triageIds[] = (int) $pdo->lastInsertId();
    $triageHit = $pdo->prepare("
        SELECT id FROM triage_results
        WHERE patient_id = ? AND assigned_provider_id = ?
          AND recommendation_status IN ('pending_approval', 'approved', 'rejected')
          AND assessed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        LIMIT 1
    ");
    $triageHit->execute([$patientA, $doctorB]);
    check(!listed($pdo, $doctorB, $patientA), '4. Triage assignment alone does not add Patient A');
    check((bool) $triageHit->fetchColumn(), '10b. Triage assignment still matches the workflow access query');
    $pdo->prepare('DELETE FROM triage_results WHERE id = ?')->execute([$triageIds[0]]);

    $insertReferral = $pdo->prepare('
        INSERT INTO digital_referrals (patient_id, provider_id, referral_type, reason, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ');
    $insertReferral->execute([$patientA, $doctorB, 'LAB', $marker]);
    $referralIds[] = (int) $pdo->lastInsertId();
    $referralAccess = provider_patient_assert_access($pdo, $doctorB, $patientA, 0);
    check(!listed($pdo, $doctorB, $patientA), '5. Referral alone does not add Patient A');
    check(!empty($referralAccess['allowed']), '10c. Referral still passes workflow access');
    $pdo->prepare('DELETE FROM digital_referrals WHERE id = ?')->execute([$referralIds[0]]);

    $insertRequest = $pdo->prepare("
        INSERT INTO patient_medical_update_requests (patient_id, provider_id, status, created_at)
        VALUES (?, ?, 'pending', NOW())
    ");
    $insertRequest->execute([$patientA, $doctorB]);
    $requestIds[] = (int) $pdo->lastInsertId();
    $requestHit = $pdo->prepare("
        SELECT id FROM patient_medical_update_requests
        WHERE patient_id = ? AND provider_id = ? AND status IN ('pending', 'in_review')
        LIMIT 1
    ");
    $requestHit->execute([$patientA, $doctorB]);
    check(!listed($pdo, $doctorB, $patientA), '6. Health-summary assignment alone does not add Patient A');
    check((bool) $requestHit->fetchColumn(), '10d. Health-summary assignment still matches the workflow access query');
    check(!gisListed($gis, $doctorB, $patientA), '8b. GIS patient API still hides Patient A after health-summary assignment');
    check(listed($pdo, $doctorA, $patientA), '9b. Doctor A still lists Patient A after Doctor B assignments');

    check(
        str_contains($recordsSrc, 'provider_patient_has_consultation_history($pdo, $provider_id, $requested_id)')
        && str_contains($recordsSrc, '$mr_access_denied = true')
        && !str_contains($recordsSrc, 'provider_patient_assert_access'),
        '7b. medical_records.php requires consultation history for ?patient_id='
    );
    check(
        !str_contains($recordsSrc, "\$patients[] = ["),
        '2b. medical_records.php does not append health-summary patients to the list'
    );
    check(
        !str_contains($accessSrc, 'UNION ALL')
        && str_contains($accessSrc, 'FROM consultations')
        && str_contains($accessSrc, 'WHERE provider_id = ?'),
        '1b. caseload directory SQL keeps the consultation relationship only'
    );
    check(
        str_contains($gisSrc, 'EXISTS (SELECT 1 FROM consultations c WHERE c.patient_id = u.id AND c.provider_id = ?)')
        && !str_contains($gisSrc, 'appointment_slots a')
        && !str_contains($gisSrc, 'patient_medical_update_requests hs'),
        '8c. GIS caseload filter is consultation-only'
    );
    check(
        str_contains($accessSrc, 'FROM appointment_slots')
        && str_contains($accessSrc, 'FROM digital_referrals')
        && str_contains($accessSrc, 'FROM triage_results')
        && str_contains($accessSrc, 'FROM patient_medical_update_requests')
        && str_contains($referralApi, 'provider_patient_assert_access')
        && str_contains($triageApi, 'provider_patient_assert_access')
        && str_contains($summaryApi, 'provider_patient_assert_access'),
        '10. Care-tip, referral, and health-summary APIs still use workflow access'
    );
    check(
        str_contains($historySrc, 'WHERE c.provider_id = ?')
        && str_contains($historySrc, 'WHERE c.patient_id = ? AND c.provider_id = ?'),
        'Consultation history stays limited to this doctor'
    );
    check(
        str_contains($gisSrc, "strtolower(trim(\$viewerRole)) === 'provider'")
        && str_contains($gisSrc, "\$viewerRole === 'provider'"),
        'Admin and SuperAdmin GIS queries are not passed through the doctor filter'
    );
} catch (Throwable $e) {
    $fail++;
    echo 'FAIL  test aborted: ' . $e->getMessage() . "\n";
} finally {
    if ($requestIds !== []) {
        $pdo->exec('DELETE FROM patient_medical_update_requests WHERE id IN (' . implode(',', array_map('intval', $requestIds)) . ')');
    }
    if ($referralIds !== []) {
        $pdo->exec('DELETE FROM digital_referrals WHERE id IN (' . implode(',', array_map('intval', $referralIds)) . ')');
    }
    if ($triageIds !== []) {
        $pdo->exec('DELETE FROM triage_results WHERE id IN (' . implode(',', array_map('intval', $triageIds)) . ')');
    }
    if ($slotIds !== []) {
        $pdo->exec('DELETE FROM appointment_slots WHERE id IN (' . implode(',', array_map('intval', $slotIds)) . ')');
    }
    if ($consultIds !== []) {
        $pdo->exec('DELETE FROM consultations WHERE id IN (' . implode(',', array_map('intval', $consultIds)) . ')');
    }
    if ($userIds !== []) {
        $pdo->exec('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $userIds)) . ')');
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
