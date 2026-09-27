<?php
/**
 * Doctor GIS analytics include a patient only when consultations.provider_id
 * matches that doctor. Admin and superadmin stay city-wide.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--api') {
    $spec = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true);
    if (!is_array($spec)) {
        fwrite(STDERR, "bad api spec\n");
        exit(2);
    }
    $_GET = array_merge(['action' => (string) ($spec['action'] ?? 'analytics')], $spec['extra'] ?? []);
    require $root . '/bootstrap.php';
    $_SESSION['user_role'] = (string) ($spec['role'] ?? '');
    $_SESSION['user_id'] = (int) ($spec['user_id'] ?? 0);
    $sessionName = session_name();
    $sessionId = session_id();
    session_write_close();
    $_COOKIE[$sessionName] = $sessionId;
    require $root . '/app/api/admin/gis_data.php';
    exit;
}

require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/app/core/GisDashboardService.php';

$pass = 0;
$fail = 0;

function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS  {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL  {$label}\n";
    if ($detail !== '') {
        echo "      {$detail}\n";
    }
}

function mentions(array $analytics, string $needle): bool
{
    foreach ($analytics as $rows) {
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $value) {
                if ((string) $value === $needle) {
                    return true;
                }
            }
        }
    }

    return false;
}

function sectionHas(array $analytics, string $section, string $field, string $needle): bool
{
    foreach ($analytics[$section] ?? [] as $row) {
        if (is_array($row) && (string) ($row[$field] ?? '') === $needle) {
            return true;
        }
    }

    return false;
}

/** @param array<string, mixed> $analytics */
function canon(array $analytics): string
{
    $normalized = [];
    foreach ($analytics as $section => $rows) {
        $normalized[$section] = [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $copy = [];
            foreach ($row as $key => $value) {
                $copy[(string) $key] = $key === 'count' ? (int) $value : (string) $value;
            }
            ksort($copy);
            $normalized[$section][] = $copy;
        }
        usort($normalized[$section], static function (array $a, array $b): int {
            return strcmp(json_encode($a) ?: '', json_encode($b) ?: '');
        });
    }

    return json_encode($normalized) ?: '';
}

function insertRegistration(PDO $pdo, string $email, string $name, string $province, string $municipality, string $barangay, string $condition, string $marker): int
{
    $regCols = $pdo->query('SHOW COLUMNS FROM patient_registrations')->fetchAll(PDO::FETCH_ASSOC);
    $regInsert = [
        'full_name' => $name,
        'address' => $barangay . ', ' . $municipality,
        'date_of_birth' => '1990-01-15',
        'age' => 36,
        'national_id' => substr(hash('sha256', $marker . '|' . $email), 0, 64),
        'status' => 'verified',
        'email' => $email,
        'first_name' => 'T',
        'last_name' => $name,
        'province' => $province,
        'city_municipality' => $municipality,
        'barangay' => $barangay,
        'existing_conditions' => $condition,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    $available = [];
    foreach ($regCols as $col) {
        $available[(string) $col['Field']] = $col;
    }
    $colNames = [];
    $colVals = [];
    foreach ($regInsert as $colName => $value) {
        if (!isset($available[$colName])) {
            continue;
        }
        $colNames[] = $colName;
        $colVals[] = $value;
    }
    foreach ($available as $colName => $col) {
        if (in_array($colName, $colNames, true)) {
            continue;
        }
        $nullable = strtoupper((string) ($col['Null'] ?? '')) === 'YES';
        $default = $col['Default'] ?? null;
        $extra = strtolower((string) ($col['Extra'] ?? ''));
        if ($nullable || $default !== null || str_contains($extra, 'auto_increment')) {
            continue;
        }
        $type = strtolower((string) ($col['Type'] ?? ''));
        $colNames[] = $colName;
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

    return (int) $pdo->lastInsertId();
}

function insertEmergency(PDO $pdo, int $patientId, string $complaint, ?int $assignedProviderId = null): int
{
    $pdo->prepare("
        INSERT INTO triage_results
            (patient_id, level, symptoms, chief_complaint, urgency_label, status, assessed_at,
             triage_classification, triage_level, assessment_status, assigned_provider_id, recommendation_status)
        VALUES (?, 'emergency', ?, ?, 'Emergency', 'pending', NOW(), 'EMERGENCY', 'emergency', 'COMPLETED', ?, 'hidden')
    ")->execute([$patientId, $complaint, $complaint, $assignedProviderId]);

    return (int) $pdo->lastInsertId();
}

/** @return array<string, mixed> */
function apiCall(string $role, int $userId, string $action, array $extra = []): array
{
    $specFile = tempnam(sys_get_temp_dir(), 'gisapi');
    if ($specFile === false) {
        throw new RuntimeException('Could not create API spec file.');
    }
    file_put_contents($specFile, json_encode([
        'role' => $role,
        'user_id' => $userId,
        'action' => $action,
        'extra' => $extra,
    ]));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --api ' . escapeshellarg($specFile);
    $out = shell_exec($cmd);
    @unlink($specFile);
    $raw = (string) $out;
    $jsonStart = strpos($raw, '{');
    $decoded = json_decode($jsonStart === false ? '' : substr($raw, $jsonStart), true);

    return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'non-json', 'raw' => $raw];
}

function patientIncluded(array $analytics, string $province, string $municipality, string $barangay, string $symptom, string $condition): bool
{
    return sectionHas($analytics, 'by_province', 'label', $province)
        && sectionHas($analytics, 'by_municipality', 'label', $municipality)
        && sectionHas($analytics, 'by_barangay', 'label', $barangay)
        && sectionHas($analytics, 'consultations', 'barangay', $barangay)
        && sectionHas($analytics, 'emergencies', 'barangay', $barangay)
        && sectionHas($analytics, 'symptoms', 'label', $symptom)
        && sectionHas($analytics, 'symptoms', 'barangay', $barangay)
        && sectionHas($analytics, 'conditions', 'label', $condition)
        && sectionHas($analytics, 'conditions', 'barangay', $barangay);
}

function patientExcluded(array $analytics, string $province, string $municipality, string $barangay, string $symptom, string $condition): bool
{
    foreach ([$province, $municipality, $barangay, $symptom, $condition] as $needle) {
        if (mentions($analytics, $needle)) {
            return false;
        }
    }

    return true;
}

$marker = 'gisprv_' . bin2hex(random_bytes(3));
$userIds = [];
$regIds = [];
$consultIds = [];
$triageIds = [];
$slotIds = [];
$referralIds = [];
$requestIds = [];
$locationPatientIds = [];

try {
    $insertUser = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())');
    $people = [
        ['patient', 'A'],
        ['patient', 'B'],
        ['patient', 'C'],
        ['patient', 'Book'],
        ['patient', 'Triage'],
        ['patient', 'Ref'],
        ['patient', 'Sum'],
        ['provider', 'DA'],
        ['provider', 'DB'],
        ['admin', 'AD'],
        ['superadmin', 'SA'],
    ];
    foreach ($people as $person) {
        $insertUser->execute([
            'T',
            $person[1],
            $marker . '_' . strtolower($person[1]) . '@t.test',
            password_hash('x', PASSWORD_DEFAULT),
            $person[0],
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }
    [$patientA, $patientB, $patientC, $patientBook, $patientTriage, $patientRef, $patientSum, $doctorA, $doctorB, $adminId, $superId] = $userIds;

    $profiles = [
        'A' => [$patientA, 'ProvA', 'MunA', 'BrgyA', 'CondA', 'SymptomA'],
        'B' => [$patientB, 'ProvB', 'MunB', 'BrgyB', 'CondB', 'SymptomB'],
        'C' => [$patientC, 'ProvC', 'MunC', 'BrgyC', 'CondC', 'SymptomC'],
        'Book' => [$patientBook, 'ProvBook', 'MunBook', 'BrgyBook', 'CondBook', 'SymptomBook'],
        'Triage' => [$patientTriage, 'ProvTriage', 'MunTriage', 'BrgyTriage', 'CondTriage', 'SymptomTriage'],
        'Ref' => [$patientRef, 'ProvRef', 'MunRef', 'BrgyRef', 'CondRef', 'SymptomRef'],
        'Sum' => [$patientSum, 'ProvSum', 'MunSum', 'BrgySum', 'CondSum', 'SymptomSum'],
    ];
    $tokens = [];
    foreach ($profiles as $key => $profile) {
        [$pid, $province, $municipality, $barangay, $condition, $symptom] = $profile;
        $tokens[$key] = [
            $marker . $province,
            $marker . $municipality,
            $marker . $barangay,
            $marker . $symptom,
            $marker . $condition,
        ];
        $email = $marker . '_' . strtolower($key) . '@t.test';
        $regIds[] = insertRegistration(
            $pdo,
            $email,
            $key,
            $tokens[$key][0],
            $tokens[$key][1],
            $tokens[$key][2],
            $tokens[$key][4],
            $marker
        );
        $assigned = $key === 'Triage' ? $doctorA : null;
        $triageIds[] = insertEmergency($pdo, $pid, $tokens[$key][3], $assigned);
        $locationPatientIds[] = $pid;
    }

    $insertConsult = $pdo->prepare('
        INSERT INTO consultations (patient_id, provider_id, provider_name, consult_date, consult_time, consult_type, status, created_at)
        VALUES (?, ?, ?, CURDATE(), ?, ?, ?, NOW())
    ');
    $insertConsult->execute([$patientA, $doctorA, 'Doctor A', '09:10:00', 'General Consultation', 'completed']);
    $consultIds[] = (int) $pdo->lastInsertId();
    $insertConsult->execute([$patientB, $doctorB, 'Doctor B', '09:20:00', 'General Consultation', 'completed']);
    $consultIds[] = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO appointment_slots (provider_id, patient_id, slot_date, start_time, end_time, status)
        VALUES (?, ?, CURDATE(), '23:17:00', '23:47:00', 'booked')
    ")->execute([$doctorA, $patientBook]);
    $slotIds[] = (int) $pdo->lastInsertId();

    $pdo->prepare('
        INSERT INTO digital_referrals (patient_id, provider_id, referral_type, reason, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ')->execute([$patientRef, $doctorA, 'LAB', $marker]);
    $referralIds[] = (int) $pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO patient_medical_update_requests (patient_id, provider_id, status, created_at)
        VALUES (?, ?, 'pending', NOW())
    ")->execute([$patientSum, $doctorA]);
    $requestIds[] = (int) $pdo->lastInsertId();

    $gis = new GisDashboardService($pdo);
    $analyticsA = $gis->getAnalytics('provider', $doctorA);
    $analyticsB = $gis->getAnalytics('provider', $doctorB);
    $analyticsAdmin = $gis->getAnalytics('admin', $adminId);
    $analyticsSuper = $gis->getAnalytics('superadmin', $superId);
    $analyticsDefault = $gis->getAnalytics();

    [$provA, $munA, $brgyA, $symA, $condA] = $tokens['A'];
    [$provB, $munB, $brgyB, $symB, $condB] = $tokens['B'];
    [$provC, $munC, $brgyC, $symC, $condC] = $tokens['C'];

    check(
        patientIncluded($analyticsA, $provA, $munA, $brgyA, $symA, $condA),
        '1. Doctor A GIS analytics includes Patient A province, municipality, barangay, consultation, emergency, symptom, and condition'
    );
    check(
        patientExcluded($analyticsA, $provB, $munB, $brgyB, $symB, $condB)
        && patientExcluded($analyticsA, $provC, $munC, $brgyC, $symC, $condC),
        '2. Doctor A GIS analytics does not include Patient B or Patient C'
    );
    check(
        patientIncluded($analyticsB, $provB, $munB, $brgyB, $symB, $condB),
        '3. Doctor B GIS analytics includes Patient B province, municipality, barangay, consultation, emergency, symptom, and condition'
    );
    check(
        patientExcluded($analyticsB, $provA, $munA, $brgyA, $symA, $condA)
        && patientExcluded($analyticsB, $provC, $munC, $brgyC, $symC, $condC),
        '4. Doctor B GIS analytics does not include Patient A or Patient C'
    );
    check(
        patientExcluded($analyticsA, $tokens['Book'][0], $tokens['Book'][1], $tokens['Book'][2], $tokens['Book'][3], $tokens['Book'][4]),
        '5. A patient who only has a booking does not enter Doctor A analytics'
    );
    check(
        patientExcluded($analyticsA, $tokens['Triage'][0], $tokens['Triage'][1], $tokens['Triage'][2], $tokens['Triage'][3], $tokens['Triage'][4]),
        '6. A patient who only has a triage assignment does not enter Doctor A analytics'
    );
    check(
        patientExcluded($analyticsA, $tokens['Ref'][0], $tokens['Ref'][1], $tokens['Ref'][2], $tokens['Ref'][3], $tokens['Ref'][4]),
        '7. A patient who only has a referral does not enter Doctor A analytics'
    );
    check(
        patientExcluded($analyticsA, $tokens['Sum'][0], $tokens['Sum'][1], $tokens['Sum'][2], $tokens['Sum'][3], $tokens['Sum'][4]),
        '8. A patient who only has a health-summary assignment does not enter Doctor A analytics'
    );
    $adminCityWide = patientIncluded($analyticsAdmin, $provA, $munA, $brgyA, $symA, $condA)
        && patientIncluded($analyticsAdmin, $provB, $munB, $brgyB, $symB, $condB)
        && sectionHas($analyticsAdmin, 'by_province', 'label', $provC)
        && sectionHas($analyticsAdmin, 'by_municipality', 'label', $munC)
        && sectionHas($analyticsAdmin, 'by_barangay', 'label', $brgyC)
        && sectionHas($analyticsAdmin, 'emergencies', 'barangay', $brgyC)
        && sectionHas($analyticsAdmin, 'symptoms', 'label', $symC)
        && sectionHas($analyticsAdmin, 'conditions', 'label', $condC)
        && !sectionHas($analyticsAdmin, 'consultations', 'barangay', $brgyC)
        && canon($analyticsAdmin) === canon($analyticsDefault)
        && canon($analyticsAdmin) !== canon($analyticsA)
        && canon($analyticsAdmin) !== canon($analyticsB);
    check(
        $adminCityWide,
        '9. Admin still receives city-wide analytics, including Patient A, B, and C'
    );
    check(
        sectionHas($analyticsSuper, 'by_province', 'label', $provA)
        && sectionHas($analyticsSuper, 'by_province', 'label', $provB)
        && sectionHas($analyticsSuper, 'by_province', 'label', $provC)
        && sectionHas($analyticsSuper, 'consultations', 'barangay', $brgyA)
        && sectionHas($analyticsSuper, 'consultations', 'barangay', $brgyB)
        && canon($analyticsSuper) === canon($analyticsAdmin),
        '10. SuperAdmin still receives the same city-wide analytics as Admin'
    );
    check(canon($analyticsA) !== canon($analyticsB), 'Doctor A and Doctor B receive different scoped analytics');

    $apiAnalytics = apiCall('provider', $doctorA, 'analytics', [
        'provider_id' => (string) $doctorB,
        'viewer_role' => 'admin',
        'user_id' => (string) $doctorB,
    ]);
    $apiLight = apiCall('provider', $doctorA, 'light_bundle', [
        'provider_id' => (string) $doctorB,
        'viewer_role' => 'superadmin',
    ]);
    $apiBundle = apiCall('provider', $doctorA, 'bundle', [
        'provider_id' => (string) $adminId,
        'role' => 'admin',
    ]);
    $apiDoctorB = apiCall('provider', $doctorB, 'analytics');
    $apiAdmin = apiCall('admin', $adminId, 'analytics', [
        'provider_id' => (string) $doctorA,
    ]);
    $scoped = canon($analyticsA);

    check(
        ($apiAnalytics['success'] ?? false) === true
        && canon($apiAnalytics['data']['analytics'] ?? []) === $scoped,
        '11. action=analytics is scoped to the logged-in doctor'
    );
    check(
        ($apiLight['success'] ?? false) === true
        && canon($apiLight['data']['analytics'] ?? []) === $scoped,
        '12. action=light_bundle analytics payload is scoped to the logged-in doctor'
    );
    $bundlePatients = $apiBundle['data']['patients'] ?? [];
    $bundleIds = [];
    if (is_array($bundlePatients)) {
        foreach ($bundlePatients as $row) {
            if (is_array($row)) {
                $bundleIds[] = (int) ($row['patient_id'] ?? 0);
            }
        }
    }
    check(
        ($apiBundle['success'] ?? false) === true
        && canon($apiBundle['data']['analytics'] ?? []) === $scoped
        && in_array($patientA, $bundleIds, true)
        && !in_array($patientB, $bundleIds, true)
        && !in_array($patientC, $bundleIds, true),
        '13. action=bundle analytics payload is scoped to the logged-in doctor'
    );
    $zero = $gis->getAnalytics('provider', 0);
    check(
        canon($apiAnalytics['data']['analytics'] ?? []) === $scoped
        && canon($apiDoctorB['data']['analytics'] ?? []) === canon($analyticsB)
        && canon($apiAdmin['data']['analytics'] ?? []) === canon($analyticsAdmin)
        && canon($zero) !== $scoped
        && !mentions($zero, $provA),
        '14. Direct API query parameters cannot switch the provider scope',
        'a=' . (canon($apiAnalytics['data']['analytics'] ?? []) === $scoped ? 'y' : 'n')
        . ' b=' . (canon($apiDoctorB['data']['analytics'] ?? []) === canon($analyticsB) ? 'y' : 'n')
        . ' admin=' . (canon($apiAdmin['data']['analytics'] ?? []) === canon($analyticsAdmin) ? 'y' : 'n')
        . ' adminSuccess=' . (($apiAdmin['success'] ?? false) ? 'y' : 'n')
        . ' bSuccess=' . (($apiDoctorB['success'] ?? false) ? 'y' : 'n')
        . ' zero=' . (canon($zero) !== $scoped && !mentions($zero, $provA) ? 'y' : 'n')
        . ' adminMsg=' . substr((string) ($apiAdmin['message'] ?? $apiAdmin['raw'] ?? ''), 0, 180)
    );

    $patientLevel = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/dev/test_provider_patient_list_consultation_only.php');
    $patientOut = [];
    $patientCode = 1;
    exec($patientLevel, $patientOut, $patientCode);
    check($patientCode === 0, '15. Existing patient-level GIS consultation filter still passes');
    if ($patientCode !== 0) {
        echo implode("\n", $patientOut) . "\n";
    }

    $gisSrc = (string) file_get_contents($root . '/app/core/GisDashboardService.php');
    $apiSrc = (string) file_get_contents($root . '/app/api/admin/gis_data.php');
    check(
        str_contains($gisSrc, 'EXISTS (SELECT 1 FROM consultations c WHERE c.patient_id = u.id AND c.provider_id = ?)')
        && str_contains($gisSrc, 'WHERE c.provider_id = ?')
        && !str_contains($apiSrc, '$_GET[\'provider_id\']')
        && str_contains($apiSrc, '$_SESSION[\'user_id\']')
        && substr_count($apiSrc, 'getAnalytics($role, $viewerId)') === 3,
        'Analytics SQL uses consultations.provider_id and the API passes the session user'
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
    if ($slotIds !== []) {
        $pdo->exec('DELETE FROM appointment_slots WHERE id IN (' . implode(',', array_map('intval', $slotIds)) . ')');
    }
    if ($triageIds !== []) {
        $pdo->exec('DELETE FROM triage_results WHERE id IN (' . implode(',', array_map('intval', $triageIds)) . ')');
    }
    if ($consultIds !== []) {
        $pdo->exec('DELETE FROM consultations WHERE id IN (' . implode(',', array_map('intval', $consultIds)) . ')');
    }
    if ($locationPatientIds !== []) {
        $pdo->exec('DELETE FROM patient_locations WHERE patient_id IN (' . implode(',', array_map('intval', $locationPatientIds)) . ')');
    }
    if ($regIds !== []) {
        $pdo->exec('DELETE FROM patient_registrations WHERE id IN (' . implode(',', array_map('intval', $regIds)) . ')');
    }
    if ($userIds !== []) {
        $pdo->exec('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $userIds)) . ')');
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
