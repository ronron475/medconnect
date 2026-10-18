<?php
/**
 * Patient visit status. No database connection.
 *
 * CLI: php scripts/test/patient_consultation_status_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/includes/patient_consultation_records.php';

$pass = 0;
$fail = 0;

function status_assert(bool $ok, string $label): void
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

$inProgress = patient_consultation_patient_status_label('in_consultation', '', null);
status_assert($inProgress === 'In progress', 'video ended with no finalized SOAP stays In progress');
status_assert(patient_consultation_patient_status_chip($inProgress) !== 'completed', 'the in-progress chip is not the completed style');

$draft = patient_consultation_patient_status_label('in_consultation', '', null);
status_assert($draft === 'In progress', 'a saved draft still shows In progress');
status_assert(patient_consultation_is_finalized('in_consultation', 'draft subjective', null) === false, 'a draft note is not a finalized record');
status_assert(patient_consultation_is_finalized('in_consultation', '', '2026-10-19 01:00:00') === false, 'a timestamp without a completed consultation does not finalize the note');

$done = patient_consultation_patient_status_label('completed', 'signed_pdf:abc', '2026-10-19 01:00:00');
status_assert($done === 'Completed', 'a completed consultation with a finalized note shows Completed');
status_assert(patient_consultation_is_finalized('completed', 'signed_pdf:abc', '2026-10-19 01:00:00') === true, 'the finalized record is visible to the owning patient rules');
status_assert(str_contains(patient_consultation_record_visible_sql('c', 'cn'), "c.status = 'completed'"), 'patient record queries require a completed consultation');
status_assert(str_contains(patient_consultation_record_visible_sql('c', 'cn'), 'cn.finalized_at'), 'patient record queries require a finalized note');

$failed = patient_consultation_patient_status_label('completed', '', null);
status_assert($failed === 'In progress', 'a completed status without a finalized note does not show Completed');
status_assert(patient_consultation_patient_status_label('in_consultation', '', null) !== 'Completed', 'a failed finalization that leaves the visit open does not show Completed');
status_assert(patient_consultation_patient_status_label('cancelled', 'signed_pdf:abc', '2026-10-19 01:00:00') === 'Cancelled', 'a cancelled visit stays Cancelled');

$detail = (string) file_get_contents($root . '/resources/views/patient/consultation_detail.php');
$summary = (string) file_get_contents($root . '/app/api/consultations/session_summary.php');
$end = (string) file_get_contents($root . '/app/api/consultations/end_video.php');
$save = (string) file_get_contents($root . '/app/api/provider/save_clinical_notes.php');

status_assert(str_contains($detail, 'patient_consultation_patient_status_label('), 'the consultation detail page uses the shared patient status');
status_assert(!str_contains($detail, "\$video['status']"), 'the consultation detail page does not treat an ended video as completion');
status_assert(str_contains($detail, 'elseif (!$isFinalized)'), 'draft documentation stays behind the not-finalized branch');
$docsAt = strpos($detail, 'elseif (!$isFinalized)');
$soapAt = strpos($detail, 'SOAP Note');
status_assert($docsAt !== false && $soapAt !== false && $docsAt < $soapAt, 'the SOAP note is rendered only after the finalized branch');
status_assert(str_contains($summary, 'patient_consultation_patient_status_label('), 'the patient session summary uses the shared patient status');
status_assert(!str_contains($summary, "?? 'Completed'"), 'the session summary does not default the patient label to Completed');
status_assert(!str_contains($end, "status = 'completed'"), 'ending the video still does not mark the consultation completed');
status_assert(str_contains($save, "SET status = 'completed'"), 'only SOAP finalization still marks the consultation completed');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
