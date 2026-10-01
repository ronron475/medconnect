<?php
/**
 * GIS Patient Map "Pending Care tips review" must follow the same current
 * care-tip review rules as doctor approval / patient pending-review helpers.
 *
 * Run: php scripts/dev/test_gis_pending_care_tips_status.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/app/core/GisDashboardService.php';
require_once $root . '/app/includes/triage_assessment_schema.php';

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

/**
 * @param array<string, mixed> $filters
 * @return array<string, mixed>|null
 */
function gisRow(GisDashboardService $gis, int $doctorId, int $patientId): ?array
{
    $rows = $gis->getPatientRecords([
        'provider_id' => $doctorId,
        'patient_ids' => [$patientId],
    ], 'provider');
    foreach ($rows as $row) {
        if ((int) ($row['patient_id'] ?? 0) === $patientId) {
            return $row;
        }
    }

    return null;
}

function gisLabel(?array $row): string
{
    return (string) ($row['gis_status_label'] ?? '');
}

$tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Manila');
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$yesterday = (new DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d');

$marker = 'gisct_' . bin2hex(random_bytes(3));
$userIds = [];
$consultIds = [];
$triageIds = [];

$insertTriage = $pdo->prepare("
    INSERT INTO triage_results
        (patient_id, symptoms, chief_complaint, level, urgency_label, status, assessed_at,
         recommendation_status, assigned_provider_id, triage_level, triage_classification,
         recommendations, outcome, assessment_status)
    VALUES
        (?, 'cough', 'cough for two days', '3', 'Non-Urgent (Routine)', ?, ?,
         ?, ?, 'non_urgent', 'NON_URGENT',
         ?, ?, ?)
");

try {
    $gisSrc = (string) file_get_contents($root . '/app/core/GisDashboardService.php');
    $helperSql = triage_sql_current_pending_care_tips_review('prw');
    check(
        str_contains($gisSrc, 'triage_sql_current_pending_care_tips_review')
        && !str_contains($gisSrc, "prw.recommendation_status = 'pending_approval')"),
        'GIS pending_review uses the shared current-review helper'
    );
    check(
        str_contains($helperSql, 'provider_triage_row_visibility_sql')
        || (
            str_contains($helperSql, 'assigned_provider_id = ?')
            && str_contains($helperSql, "recommendation_status, 'hidden') = 'pending_approval'")
            && str_contains($helperSql, 'DATE(')
            && str_contains($helperSql, 'CURDATE()')
            && str_contains($helperSql, 'NOT EXISTS')
            && str_contains($helperSql, 'IN_PROGRESS')
        ),
        'Helper composes assigned-doctor, pending_approval, same-day, active-only, latest-finished'
    );
    check(
        str_contains($helperSql, 'assigned_provider_id = ?')
        && str_contains($helperSql, "NOT IN ('CANCELLED', 'CANCELED')")
        && str_contains($helperSql, "status, 'pending') = 'pending'")
        && str_contains($helperSql, 'terminated'),
        'Helper reuses doctor visibility + can-decide predicates'
    );

    $insertUser = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role, is_active, created_at) VALUES (?,?,?,?,?,1,NOW())');
    foreach ([['patient', 'P'], ['provider', 'DA'], ['provider', 'DB']] as $user) {
        $insertUser->execute([
            'GisCt',
            $user[1],
            $marker . '_' . strtolower($user[1]) . '@t.test',
            password_hash('x', PASSWORD_DEFAULT),
            $user[0],
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }
    [$patientId, $doctorA, $doctorB] = $userIds;

    $insertConsult = $pdo->prepare('
        INSERT INTO consultations (patient_id, provider_id, provider_name, consult_date, consult_time, consult_type, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    $insertConsult->execute([$patientId, $doctorA, 'Doctor A', $yesterday, '09:00:00', 'General Consultation', 'completed']);
    $consultIds[] = (int) $pdo->lastInsertId();

    $gis = new GisDashboardService($pdo);

    $row = gisRow($gis, $doctorA, $patientId);
    check($row !== null, 'Patient is on Doctor A Patient Map after a prior consultation');
    check(
        gisLabel($row) !== 'Pending Care tips review',
        'CASE 8: no pending care-tip review → does not show Pending Care tips review'
    );

    $insertTriage->execute([
        $patientId, 'pending', $today . ' 09:00:00',
        'pending_approval', $doctorB,
        "Rest and drink fluids.\nMonitor symptoms.",
        'awaiting_provider_review', 'COMPLETED',
    ]);
    $otherDoctorTriage = (int) $pdo->lastInsertId();
    $triageIds[] = $otherDoctorTriage;
    $row = gisRow($gis, $doctorA, $patientId);
    check(
        gisLabel($row) !== 'Pending Care tips review',
        'CASE 6: pending row belongs to another doctor → does not show it'
    );
    $pdo->prepare('DELETE FROM triage_results WHERE id = ?')->execute([$otherDoctorTriage]);

    $insertTriage->execute([
        $patientId, 'pending', $today . ' 09:10:00',
        'pending_approval', $doctorA,
        "Rest and drink fluids.\nMonitor symptoms.",
        'awaiting_provider_review', 'COMPLETED',
    ]);
    $currentTriage = (int) $pdo->lastInsertId();
    $triageIds[] = $currentTriage;
    $row = gisRow($gis, $doctorA, $patientId);
    check(
        gisLabel($row) === 'Pending Care tips review',
        'CASE 1: current valid pending care-tip review → shows Pending Care tips review'
    );
    check(
        gisLabel($row) === 'Pending Care tips review',
        'CASE 7: current valid pending row belongs to this doctor → shows it'
    );
    check(
        strtolower((string) ($row['triage_level'] ?? '')) === 'non_urgent'
        && strcasecmp((string) ($row['triage_label'] ?? ''), 'Non-Urgent') === 0,
        'NON-URGENT badge stays Non-Urgent for the current finished assessment'
    );

    $insertConsult->execute([$patientId, $doctorA, 'Doctor A', $today, '14:00:00', 'General Consultation', 'scheduled']);
    $scheduledId = (int) $pdo->lastInsertId();
    $consultIds[] = $scheduledId;
    $row = gisRow($gis, $doctorA, $patientId);
    check(
        gisLabel($row) === 'Scheduled',
        'CASE 5: already-booked/current scheduled consult keeps Scheduled priority'
    );
    $pdo->prepare('DELETE FROM consultations WHERE id = ?')->execute([$scheduledId]);

    $pdo->prepare("
        UPDATE triage_results
        SET outcome = 'cancelled', assessment_status = 'CANCELLED'
        WHERE id = ?
    ")->execute([$currentTriage]);
    $row = gisRow($gis, $doctorA, $patientId);
    check(
        gisLabel($row) !== 'Pending Care tips review',
        'CASE 3: cancelled pending row → does not show it'
    );
    $pdo->prepare('DELETE FROM triage_results WHERE id = ?')->execute([$currentTriage]);

    $insertTriage->execute([
        $patientId, 'pending', $yesterday . ' 16:00:00',
        'pending_approval', $doctorA,
        "Rest and drink fluids.\nMonitor symptoms.",
        'awaiting_provider_review', 'COMPLETED',
    ]);
    $expiredTriage = (int) $pdo->lastInsertId();
    $triageIds[] = $expiredTriage;
    $row = gisRow($gis, $doctorA, $patientId);
    check(
        gisLabel($row) !== 'Pending Care tips review',
        'CASE 4: expired pending row → does not show it'
    );
    $pdo->prepare('DELETE FROM triage_results WHERE id = ?')->execute([$expiredTriage]);

    $insertTriage->execute([
        $patientId, 'pending', $today . ' 08:00:00',
        'pending_approval', $doctorA,
        "Rest and drink fluids.\nMonitor symptoms.",
        'awaiting_provider_review', 'COMPLETED',
    ]);
    $oldPending = (int) $pdo->lastInsertId();
    $triageIds[] = $oldPending;
    $insertTriage->execute([
        $patientId, 'completed', $today . ' 11:00:00',
        'hidden', $doctorA,
        'Schedule a routine consultation if symptoms persist.',
        'visit_completed', 'COMPLETED',
    ]);
    $newerCase = (int) $pdo->lastInsertId();
    $triageIds[] = $newerCase;
    $row = gisRow($gis, $doctorA, $patientId);
    check(
        gisLabel($row) !== 'Pending Care tips review',
        'CASE 2: old pending_approval + newer completed/current case → does not show it'
    );
    check(
        strtolower((string) ($row['triage_level'] ?? '')) === 'non_urgent',
        'NON-URGENT badge still follows the latest finished assessment after CASE 2'
    );
} catch (Throwable $e) {
    $fail++;
    echo 'FAIL  test aborted: ' . $e->getMessage() . "\n";
} finally {
    if ($triageIds !== []) {
        $pdo->exec('DELETE FROM triage_results WHERE id IN (' . implode(',', array_map('intval', $triageIds)) . ')');
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
