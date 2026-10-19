<?php
/**
 * Comprehensive regression tests for Issues 3, 4, 5, and 6:
 * - Issue 3: Standardize patient notification links (canonical consultation detail routing)
 * - Issue 4: Prevent redundant provider follow-up reminders
 * - Issue 5: BHW privacy messaging without weakening consent checks
 * - Issue 6: Mocked SMS retry behavior
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/bootstrap.php';
require_once $root . '/config/db.php';
require_once $root . '/app/includes/patient_consultation_records.php';
require_once $root . '/app/includes/bhw_prescription_access.php';
require_once $root . '/app/core/TextBeeSmsClient.php';
require_once $root . '/app/includes/followup_sms.php';
require_once $root . '/app/includes/notification_events.php';

$pass = 0;
$fail = 0;

function t_assert(bool $cond, string $msg): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS  {$msg}\n";
    } else {
        $fail++;
        echo "FAIL  {$msg}\n";
    }
}

// -----------------------------------------------------------------------------
// Issue 3: Standardize Patient Notification Links
// -----------------------------------------------------------------------------

$urlPlain = patient_consultation_detail_url(123);
t_assert(str_contains($urlPlain, '/views/patient/consultation_detail.php?id=123'), 'patient_consultation_detail_url builds expected URL');
t_assert(!str_contains($urlPlain, '#'), 'patient_consultation_detail_url with no anchor has no hash');

$urlAnchor = patient_consultation_detail_url(123, 'prescriptions');
t_assert(str_ends_with($urlAnchor, '#prescriptions'), 'patient_consultation_detail_url appends anchor cleanly');

$urlHashAnchor = patient_consultation_detail_url(123, '#followup');
t_assert(str_ends_with($urlHashAnchor, '#followup') && !str_contains($urlHashAnchor, '##'), 'patient_consultation_detail_url trims leading hash');

$notifyCode = (string) file_get_contents($root . '/app/includes/notification_events.php');

// Check soapNotesFinalized
$soapStart = strpos($notifyCode, 'function soapNotesFinalized');
$soapEnd = strpos($notifyCode, 'function consultationCompleted');
$soapFn = substr($notifyCode, $soapStart, $soapEnd - $soapStart);
t_assert(str_contains($soapFn, 'patient_consultation_detail_url($consultationId'), 'soapNotesFinalized points patient to consultation detail page');
t_assert(str_contains($soapFn, 'providerDocumentation'), 'soapNotesFinalized anchors to provider documentation section');

// Check consultationCompleted
$consultStart = strpos($notifyCode, 'function consultationCompleted');
$consultEnd = strpos($notifyCode, 'function patientJoinedWaitingRoom');
$consultFn = substr($notifyCode, $consultStart, $consultEnd - $consultStart);
t_assert(str_contains($consultFn, 'patient_consultation_detail_url($consultationId)'), 'consultationCompleted points patient to consultation detail page');

// Check prescriptionAvailable
$rxStart = strpos($notifyCode, 'function prescriptionAvailable');
$rxEnd = strpos($notifyCode, 'function gisHotspotDetected');
$rxFn = substr($notifyCode, $rxStart, $rxEnd - $rxStart);
t_assert(str_contains($rxFn, "patient_consultation_detail_url(\$consultationId, 'prescriptions')"), 'prescriptionAvailable points patient to prescriptions section');

// Check followUpScheduled
$fuStart = strpos($notifyCode, 'function followUpScheduled');
$fuEnd = strpos($notifyCode, 'function emergencyConsultation');
$fuFn = substr($notifyCode, $fuStart, $fuEnd - $fuStart);
t_assert(str_contains($fuFn, "patient_consultation_detail_url(\$consultationId, 'followup')"), 'followUpScheduled points patient to followup section');

// Check consultation_detail.php anchors
$detailHtml = (string) file_get_contents($root . '/resources/views/patient/consultation_detail.php');
t_assert(str_contains($detailHtml, 'id="providerDocumentation"'), 'consultation_detail.php contains providerDocumentation anchor');
t_assert(str_contains($detailHtml, 'id="soapDocumentation"'), 'consultation_detail.php contains soapDocumentation anchor');
t_assert(str_contains($detailHtml, 'id="prescriptions"'), 'consultation_detail.php contains prescriptions anchor');
t_assert(str_contains($detailHtml, 'id="followup"'), 'consultation_detail.php contains followup anchor');

// -----------------------------------------------------------------------------
// Issue 4: Prevent Redundant Provider Follow-Up Reminders
// -----------------------------------------------------------------------------

t_assert(str_contains($consultFn, 'follow_up_decided_at'), 'consultationCompleted checks follow_up_decided_at before notifying provider');
t_assert(str_contains($consultFn, 'if (!$fuDecided)'), 'consultationCompleted suppresses provider reminder if follow-up already decided');

// Live DB test in a strictly rolled-back transaction
if ($pdo instanceof PDO) {
    $pdo->beginTransaction();
    try {
        // Find or create synthetic patient and provider user IDs
        $pId = (int) $pdo->query("SELECT id FROM users WHERE role = 'patient' LIMIT 1")->fetchColumn();
        $docId = (int) $pdo->query("SELECT id FROM users WHERE role = 'provider' LIMIT 1")->fetchColumn();

        if ($pId > 0 && $docId > 0) {
            // Case A: Consultation with follow_up_decided_at SET
            $pdo->prepare("
                INSERT INTO consultations (patient_id, provider_id, consult_date, consult_time, status, follow_up_decided_at)
                VALUES (?, ?, CURDATE(), CURTIME(), 'completed', NOW())
            ")->execute([$pId, $docId]);
            $cIdDecided = (int) $pdo->lastInsertId();

            NotificationEvents::consultationCompleted($pdo, $cIdDecided, $pId, $docId, $docId, 'Dr. Tester', 'NON-URGENT');

            $checkA = $pdo->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE user_id = ? AND related_table = 'consultations' AND related_id = ? AND title = 'Follow-Up Required'
            ");
            $checkA->execute([$docId, $cIdDecided]);
            t_assert((int) $checkA->fetchColumn() === 0, 'provider is NOT sent Follow-Up Required reminder when follow-up was already decided');

            // Case B: Consultation with follow_up_decided_at NULL
            $pdo->prepare("
                INSERT INTO consultations (patient_id, provider_id, consult_date, consult_time, status, follow_up_decided_at)
                VALUES (?, ?, CURDATE(), CURTIME(), 'completed', NULL)
            ")->execute([$pId, $docId]);
            $cIdUndecided = (int) $pdo->lastInsertId();

            NotificationEvents::consultationCompleted($pdo, $cIdUndecided, $pId, $docId, $docId, 'Dr. Tester', 'NON-URGENT');

            $checkB = $pdo->prepare("
                SELECT COUNT(*) FROM notifications
                WHERE user_id = ? AND related_table = 'consultations' AND related_id = ? AND title = 'Follow-Up Required'
            ");
            $checkB->execute([$docId, $cIdUndecided]);
            t_assert((int) $checkB->fetchColumn() === 1, 'provider IS sent Follow-Up Required reminder when follow-up was not yet decided');
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($cIdDecided) && !empty($cIdUndecided)) {
            $pdo->exec("DELETE FROM notifications WHERE related_table = 'consultations' AND related_id IN ($cIdDecided, $cIdUndecided)");
            $pdo->exec("DELETE FROM consultations WHERE id IN ($cIdDecided, $cIdUndecided)");
        }
    }
}

// -----------------------------------------------------------------------------
// Issue 5: BHW Privacy Messaging Without Weakening Consent Checks
// -----------------------------------------------------------------------------

// Ensure consultation_detail.php does NOT contain the forbidden string
t_assert(!str_contains($detailHtml, 'share_prescriptions_with_barangay_bhw'), 'consultation_detail.php preserves consent isolation test requirement');
t_assert(str_contains($detailHtml, 'Privacy Settings'), 'consultation_detail.php provides helpful link to Privacy Settings');

// Check bhw-records.js privacy messaging
$bhwJs = (string) file_get_contents($root . '/public/assets/js/bhw-records.js');
t_assert(str_contains($bhwJs, 'Prescription sharing is restricted by patient privacy settings'), 'bhw-records.js explains that sharing is restricted by privacy settings');
t_assert(str_contains($bhwJs, 'RA 10173'), 'bhw-records.js cites Data Privacy Act (RA 10173)');
t_assert(str_contains($bhwJs, 'Privacy Preferences'), 'bhw-records.js guides on how patient can grant access');

// -----------------------------------------------------------------------------
// Issue 6: Mocked SMS Retry Behavior
// -----------------------------------------------------------------------------

$smsCode = (string) file_get_contents($root . '/app/includes/followup_sms.php');
t_assert(str_contains($smsCode, '$sendEnabled = TextBeeSmsClient::sendEnabled();'), 'followup_sms.php inspects sendEnabled before duplicate query');
t_assert(str_contains($smsCode, "\$statusInSql = \$sendEnabled ? \"'accepted', 'pending'\" : \"'mocked', 'accepted', 'pending'\";"), 'followup_sms.php excludes mocked status when live sending is enabled');
t_assert(str_contains($smsCode, "'accepted'"), 'accepted status is always part of blocking statuses');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
