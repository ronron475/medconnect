<?php
/**
 * Queue-timing rules. Slot end is not a hard stop after a real join.
 * Run: php scripts/dev/test_consultation_queue_timing.php
 */
require_once dirname(__DIR__, 2) . '/app/includes/consultation_queue_timing.php';
require_once dirname(__DIR__, 2) . '/app/includes/consultation_duration.php';
require_once dirname(__DIR__, 2) . '/resources/views/provider/partials/queue_helpers.php';

$pass = 0;
$fail = 0;
$results = [];

function at(string $hm): int
{
    return strtotime('2026-09-27 ' . $hm . ':00') ?: 0;
}

function check(string $name, bool $ok): void
{
    global $pass, $fail, $results;
    $results[] = [$ok ? 'PASS' : 'FAIL', $name];
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
}

$p1Start = at('08:00');
$p1End = at('08:30');
$p2Start = at('08:30');
$p2End = at('09:00');
$p3Start = at('09:00');
$p3End = at('09:30');

// Case 1 — normal join inside the slot, doctor ends before the slot.
check('Case 1 normal: joined visit is not a no-show at 8:25',
    consultation_timing_should_noshow(at('08:25'), $p1End, 'in_consultation', true, false, '') === false);
check('Case 1 normal: doctor may keep the already-started visit',
    consultation_timing_provider_start_decision(at('08:25'), $p1Start, $p1End, 'in_consultation', false, true, false, '', true)['allowed'] === true);

// Case 2 — join at 8:29, continue after 8:30.
check('Case 2 late within slot: 8:29 is not missed',
    consultation_timing_missed_decision(at('08:29'), $p1End, false, false, 'scheduled')['missed'] === false);
check('Case 2 late within slot: joined patient continues after 8:30',
    consultation_timing_should_noshow(at('08:31'), $p1End, 'in_consultation', true, false, '') === false);
check('Case 2 one minute left: provider can start',
    consultation_timing_provider_start_decision(at('08:29'), $p1Start, $p1End, 'scheduled', false, false, false, '', false)['allowed'] === true);

// Case 3 — overtime, P2 waits and is not dropped.
check('Case 3 overtime: P2 waiting behind P1 is not a no-show',
    consultation_timing_should_noshow(at('08:50'), $p2End, 'scheduled', false, true, '') === false);
check('Case 3 overtime: P2 cannot be started while P1 video is active',
    consultation_timing_provider_start_decision(at('08:50'), $p2Start, $p2End, 'scheduled', true, false, true, '', false)['code'] === 'doctor_busy');
check('Case 3 overtime: P2 cannot start while P1 is still in consultation after the video ends',
    consultation_timing_provider_start_decision(at('09:00'), $p2Start, $p2End, 'scheduled', false, false, true, '', false, true)['code'] === 'clinical_active');
check('Case 3 overtime: P2 can start after P1 is clinically finished',
    consultation_timing_provider_start_decision(at('09:00'), $p2Start, $p2End, 'scheduled', false, false, true, '', false, false)['allowed'] === true);

// Case 4 — never joined, tries after the slot.
$missed = consultation_timing_missed_decision(at('08:31'), $p1End, false, false, 'scheduled');
check('Case 4 missed slot: P1 is missed at 8:31', $missed['missed'] === true);
check('Case 4 missed slot: P1 cannot be started ahead of P2',
    consultation_timing_provider_start_decision(at('08:31'), $p1Start, $p1End, 'scheduled', false, false, false, '', false)['code'] === 'missed');
check('Case 4 missed slot: P2 at 8:31 is not missed',
    consultation_timing_missed_decision(at('08:31'), $p2End, false, false, 'scheduled')['missed'] === false);
check('Case 4 missed slot: P2 can be started when the doctor is free',
    consultation_timing_provider_start_decision(at('08:31'), $p2Start, $p2End, 'scheduled', false, false, false, '', false)['allowed'] === true);

// Case 5 — several patients wait; only one active video.
check('Case 5 queue: P2 still waiting at 9:10 is not a no-show',
    consultation_timing_should_noshow(at('09:10'), $p2End, 'scheduled', false, true, '') === false);
check('Case 5 queue: P3 still waiting at 9:10 is not a no-show',
    consultation_timing_should_noshow(at('09:10'), $p3End, 'scheduled', false, true, '') === false);
check('Case 5 queue: a second video cannot start',
    consultation_timing_provider_start_decision(at('09:10'), $p2Start, $p2End, 'scheduled', true, false, true, '', false)['allowed'] === false);
check('Case 5 queue: P3 cannot jump ahead while P2 is the one being started',
    consultation_timing_provider_start_decision(at('09:10'), $p3Start, $p3End, 'scheduled', true, false, true, '', false)['code'] === 'doctor_busy');

// Case 6 — early finish.
check('Case 6 early: offer ignored before the slot is not a no-show',
    consultation_timing_should_noshow(at('08:10'), $p2End, 'scheduled', false, false, '') === false);
check('Case 6 early: Keep Scheduled Time is not a no-show',
    consultation_timing_should_noshow(at('08:20'), $p2End, 'scheduled', false, false, 'keep_time') === false);
check('Case 6 early: Join Early lets the doctor start before 8:30',
    consultation_timing_provider_start_decision(at('08:20'), $p2Start, $p2End, 'scheduled', false, false, false, 'join_early', false)['allowed'] === true);
check('Case 6 early: Keep Scheduled Time blocks an early start',
    consultation_timing_provider_start_decision(at('08:20'), $p2Start, $p2End, 'scheduled', false, false, false, 'keep_time', false)['code'] === 'before_start');

// Joined visit is never cut off only because the slot ended.
check('Joined consultation is not auto-ended at slot end',
    consultation_timing_should_noshow(at('08:31'), $p1End, 'in_consultation', true, false, '') === false);

$root = dirname(__DIR__, 2);
$extension = (string) file_get_contents($root . '/app/api/provider/check_extension.php');
check('Booked slot end time is not rewritten by extension', !str_contains($extension, 'UPDATE appointment_slots SET end_time'));

$expiry = (string) file_get_contents($root . '/app/includes/consultation_expiry.php');
check('Slot expiry no longer marks an in-progress visit completed', !str_contains($expiry, "status = 'completed'"));

$endVideo = (string) file_get_contents($root . '/app/includes/consultation_video_lifecycle.php');
check('Ending video does not mark the consultation completed', str_contains($endVideo, 'Do NOT mark the consultation completed') || str_contains($endVideo, 'Does NOT mark the consultation completed'));

$soap = (string) file_get_contents($root . '/app/api/provider/save_clinical_notes.php');
check('SOAP finalize is what sets the consultation completed', str_contains($soap, "status = 'completed'"));

$leave = (string) file_get_contents($root . '/app/api/consultations/end_video.php');
check('Patient leave keeps the consultation active', str_contains($leave, 'consultation stays active') || str_contains($leave, 'NOT completed'));

$followup = (string) file_get_contents($root . '/app/includes/consultation_followup.php');
check('Follow-up still requires a real slot when one is chosen', str_contains($followup, 'appointment_slots'));

$videoRoom = (string) file_get_contents($root . '/resources/views/consultation/video_room.php');
check('Video room no longer auto-closes when the countdown hits zero', !str_contains($videoRoom, 'Consultation time has expired. Closing the room'));

// Confirmed lifecycle fixes: one in_consultation, SOAP-only completion, joined time after video end.
$p1AfterEnd = [
    'status' => 'in_consultation',
    'patient_joined_at' => '2026-09-27 08:05:00',
    'patient_ever_joined' => true,
    'timing_missed' => false,
    'room_token' => '',
    'consult_date' => '2026-09-27',
    'consult_time' => '08:00:00',
    'slot_date' => '2026-09-27',
    'slot_start' => '08:00:00',
    'slot_end' => '08:30:00',
];
$p1StartDecision = consultation_timing_provider_start_decision(at('08:05'), $p1Start, $p1End, 'scheduled', false, false, false, '', false, false);
check('1. Doctor can start P1 when no consultation is clinically open', $p1StartDecision['allowed'] === true && $p1StartDecision['code'] === 'ok');
check('2. Ending the video does not mark the consultation completed', str_contains($endVideo, 'newly_completed') && !str_contains($endVideo, "SET status = 'completed'"));
check('3. P1 stays in_consultation after the video ends', $p1AfterEnd['status'] === 'in_consultation');
$p2Blocked = consultation_timing_provider_start_decision(at('08:40'), $p2Start, $p2End, 'scheduled', false, false, true, '', false, true);
check('4. Doctor cannot start P2 while P1 remains in_consultation', $p2Blocked['allowed'] === false && $p2Blocked['code'] === 'clinical_active');
$soapAccess = queue_session_access($p1AfterEnd);
check('5. P1 can still be opened for SOAP after the video ends', $soapAccess['allowed'] === true);
$soapPos = strpos($soap, "status = 'completed'");
$soapGate = strpos($soap, 'final_urgency_bucket');
check('6. P1 becomes completed only on the SOAP finalize path', $soapPos !== false && $soapGate !== false && $soapGate < $soapPos && str_contains($soap, 'signature_name') && str_contains($soap, 'soap_confirm'));
$violation = (string) file_get_contents($root . '/app/includes/case_reports.php');
$violationFn = substr($violation, (int) strpos($violation, 'function consultation_end_from_violation'));
$violationFn = substr($violationFn, 0, (int) strpos($violationFn, 'function case_terminate'));
check('7. Violation report cannot mark the consultation completed', !str_contains($violationFn, "status = 'completed'") && str_contains($violationFn, "status = 'ended'"));
$joinedMissed = consultation_timing_missed_decision(at('08:40'), $p1End, true, false, 'in_consultation');
check('8. A patient who joined is not timing_missed after the video ends', $joinedMissed['missed'] === false);
$patientJoin = consultation_patient_join_access($p1AfterEnd);
check('9. Patient and doctor agree the joined visit is still in consultation', $patientJoin['mode'] !== 'missed' && $soapAccess['allowed'] === true && $p1AfterEnd['status'] === 'in_consultation');
$neverJoined = consultation_timing_missed_decision(at('08:31'), $p1End, false, false, 'scheduled');
check('10. A patient who never joined is still a no-show after the slot', $neverJoined['missed'] === true && consultation_timing_should_noshow(at('08:31'), $p1End, 'in_consultation', false, false, '') === true);
check('11. Waiting P2 is not a no-show while P1 is still clinically active',
    consultation_timing_earlier_visit_blocks('in_consultation', true, false, at('08:20'), $p2Start) === true
    && consultation_timing_should_noshow(at('09:05'), $p2End, 'scheduled', false, true, '') === false);
check('12. A second in_consultation start is rejected for the same doctor',
    consultation_timing_provider_start_decision(at('08:40'), $p2Start, $p2End, 'scheduled', false, false, false, 'join_early', false, true)['code'] === 'clinical_active'
    && consultation_timing_provider_start_decision(at('08:40'), $p1Start, $p1End, 'in_consultation', false, true, false, '', false, false)['code'] === 'resume');
$patientStatus = (string) file_get_contents($root . '/app/api/consultations/consultation_status.php');
$doctorStatus = (string) file_get_contents($root . '/app/api/provider/queue_status.php');
$startVideo = (string) file_get_contents($root . '/app/api/consultations/start_video.php');
check('Status APIs keep patient_joined_at after the video is ended',
    str_contains($patientStatus, 'consultation_timing_latest_patient_joined_sql')
    && str_contains($doctorStatus, 'consultation_timing_latest_patient_joined_sql'));
check('Start path enforces one clinically open consultation',
    str_contains($startVideo, 'consultation_timing_other_open_consultation_id'));
check('Same consultation can still be resumed for SOAP',
    consultation_timing_provider_start_decision(at('08:40'), $p1Start, $p1End, 'in_consultation', false, true, false, '', false, false)['allowed'] === true);
check('Early actual start keeps the stored slot end',
    consultation_session_deadline_ts('2026-09-27 08:45:00', 1800, at('09:30')) === at('09:30'));

foreach ($results as [$status, $name]) {
    echo $status . '  ' . $name . PHP_EOL;
}
echo PHP_EOL . $pass . ' passed, ' . $fail . ' failed' . PHP_EOL;
exit($fail > 0 ? 1 : 0);
