<?php
/**
 * Reset a patient's clinical workflow without deleting the login.
 *
 * Clears waitlist, triage/care tips, consultations, complaints, and related
 * visit records so Book Consultation starts clean. Keeps users + registration.
 *
 * Usage:
 *   php scripts/dev/reset_patient_workflow.php --code=MC-000028
 *   php scripts/dev/reset_patient_workflow.php --code=MC-000028 --apply
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$code = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--code=')) {
        $code = strtoupper(trim(substr($arg, 7)));
    }
}
if ($code === '') {
    $code = 'MC-000028';
}

require_once dirname(__DIR__, 2) . '/config/db.php';

$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Database: {$dbName}\n";
echo 'Mode: ' . ($apply ? 'APPLY (clears clinical records, keeps login)' : 'DRY RUN') . "\n";
echo "Patient code: {$code}\n\n";

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() > 0;
}

$find = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.email, u.role, pr.patient_code, pr.workflow_status
    FROM users u
    LEFT JOIN patient_registrations pr ON pr.user_id = u.id
    WHERE u.role = 'patient'
      AND (
        pr.patient_code = ?
        OR (u.first_name LIKE '%Mitche%' AND u.last_name LIKE '%Yuma%')
      )
    ORDER BY u.id DESC
    LIMIT 5
");
$find->execute([$code]);
$matches = $find->fetchAll(PDO::FETCH_ASSOC);
if ($matches === []) {
    echo "No matching patient found.\n";
    exit(1);
}

echo "Matched:\n";
foreach ($matches as $row) {
    echo sprintf(
        "  #%d %s %s <%s> code=%s workflow=%s\n",
        (int) $row['id'],
        (string) $row['first_name'],
        (string) $row['last_name'],
        (string) $row['email'],
        (string) ($row['patient_code'] ?? ''),
        (string) ($row['workflow_status'] ?? '')
    );
}

$target = $matches[0];
$uid = (int) $target['id'];
$fullName = trim((string) $target['first_name'] . ' ' . (string) $target['last_name']);
if (stripos($fullName, 'Mitche') === false || stripos($fullName, 'Yuma') === false) {
    echo "\nRefusing: first match is not Mitche Ann Yuma.\n";
    exit(1);
}
if ($code !== '' && strtoupper((string) ($target['patient_code'] ?? '')) !== $code && count($matches) > 1) {
    echo "\nRefusing: multiple matches and code did not uniquely identify the account.\n";
    exit(1);
}

echo "\nResetting user #{$uid} ({$fullName})\n\n";

$steps = [];

$consultWhere = "consultation_id IN (SELECT id FROM consultations WHERE patient_id = {$uid})";
$childConsultTables = [
    'video_sessions',
    'consultation_messages',
    'consultation_ai_notes',
    'consultation_video_recordings',
    'consultation_video_segments',
    'message_chat_events',
    'soap_notes',
];
foreach ($childConsultTables as $table) {
    $steps[] = [$table, "DELETE FROM `{$table}` WHERE {$consultWhere}"];
}
$steps[] = ['consultation_clinical_support', "DELETE FROM consultation_clinical_support WHERE patient_id = {$uid} OR {$consultWhere}"];

$direct = [
    'patient_slot_waitlist' => "DELETE FROM patient_slot_waitlist WHERE patient_id = {$uid}",
    'complaint_evidence' => "DELETE FROM complaint_evidence WHERE patient_id = {$uid}",
    'patient_chief_complaints' => "DELETE FROM patient_chief_complaints WHERE patient_id = {$uid}",
    'patient_medical_update_requests' => "DELETE FROM patient_medical_update_requests WHERE patient_id = {$uid}",
    'prescriptions' => "DELETE FROM prescriptions WHERE patient_id = {$uid}",
    'clinical_notes' => "DELETE FROM clinical_notes WHERE patient_id = {$uid}",
    'digital_referrals' => "DELETE FROM digital_referrals WHERE patient_id = {$uid}",
    'case_reports' => "DELETE FROM case_reports WHERE patient_id = {$uid}",
    'urgent_followup_cases' => "DELETE FROM urgent_followup_cases WHERE patient_id = {$uid}",
    'followups' => "DELETE FROM followups WHERE patient_id = {$uid}",
    'bhw_home_visits' => "DELETE FROM bhw_home_visits WHERE patient_id = {$uid}",
    'consultations' => "DELETE FROM consultations WHERE patient_id = {$uid}",
    'triage_results' => "DELETE FROM triage_results WHERE patient_id = {$uid}",
    'notifications' => "DELETE FROM notifications WHERE user_id = {$uid} OR sender_id = {$uid}",
];
foreach ($direct as $table => $sql) {
    $steps[] = [$table, $sql];
}

$steps[] = [
    'appointment_slots (release)',
    "UPDATE appointment_slots SET status = 'available', patient_id = NULL, consultation_id = NULL WHERE patient_id = {$uid}",
];
$steps[] = [
    'patient_registrations.workflow_status',
    "UPDATE patient_registrations SET workflow_status = 'registered' WHERE user_id = {$uid}",
];

$ran = 0;
$pdo->beginTransaction();
try {
    foreach ($steps as [$label, $sql]) {
        $table = preg_replace('/\s+\(.*\)$/', '', $label) ?: $label;
        $table = explode('.', (string) $table)[0];
        if (!in_array($table, ['appointment_slots', 'patient_registrations'], true)
            && str_starts_with(ltrim($sql), 'DELETE FROM')
            && !table_exists($pdo, $table)
        ) {
            echo "{$label}: skipped (no table)\n";
            continue;
        }
        if ($table === 'appointment_slots' && !table_exists($pdo, 'appointment_slots')) {
            echo "{$label}: skipped (no table)\n";
            continue;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $count = $stmt->rowCount();
        echo sprintf("%s: %d row(s)%s\n", $label, $count, $apply ? '' : ' (rolled back — dry run)');
        $ran += $count;
    }
    if ($apply) {
        $pdo->commit();
        echo "\nDone. Login kept. Clinical workflow cleared for #{$uid}.\n";
        echo "Refresh the dashboard (hard refresh) and start a new complaint.\n";
    } else {
        $pdo->rollBack();
        echo "\nDry run only. Re-run with --apply to reset.\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Rollback: ' . $e->getMessage() . "\n");
    exit(1);
}
