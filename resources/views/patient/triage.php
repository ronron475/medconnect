<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/patient_portal_bootstrap.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';
require_once BASE_PATH . '/app/includes/triage_provider_assignment.php';
require_once BASE_PATH . '/app/includes/patient_symptoms_review_submit.php';
require_once BASE_PATH . '/app/includes/patient_chief_complaints.php';

$booking_today_ymd   = date('Y-m-d');
$booking_today_label = date('l, M j, Y');

$triage_history = [];
$pending_reg = patient_registration_load_pending_complaint($pdo, (int) $uid);
$active_chief_complaint = patient_portal_active_chief_complaint($pdo, (int) $uid);
$registration_chief_complaint = trim((string) ($active_chief_complaint['complaint'] ?? ''));
$chief_complaint_locked = !empty($active_chief_complaint['locked']) && $registration_chief_complaint !== '';
$chief_complaint_source = (string) ($active_chief_complaint['source'] ?? '');
$active_chief_complaint_triage_id = (int) ($active_chief_complaint['triage_id'] ?? 0);
$force_new_concern = (string) ($_GET['new_concern'] ?? '') === '1';
$requested_triage_id = (int) ($_GET['triage_id'] ?? 0);
if ($force_new_concern) {
    $chief_complaint_locked = false;
    $registration_chief_complaint = '';
    $chief_complaint_source = '';
    $active_chief_complaint_triage_id = 0;
} elseif ($requested_triage_id > 0) {
    require_once BASE_PATH . '/app/includes/patient_booking_status.php';
    try {
        $reqStmt = $pdo->prepare("
            SELECT id, chief_complaint, assigned_provider_id, triage_level, triage_classification, assessed_at
            FROM triage_results tr
            WHERE tr.id = ?
              AND tr.patient_id = ?
              AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
              " . patient_triage_sql_active_only('tr') . "
            LIMIT 1
        ");
        $reqStmt->execute([$requested_triage_id, (int) $uid]);
        $reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC);
        if ($reqRow) {
            $reqComplaint = trim((string) ($reqRow['chief_complaint'] ?? ''));
            if ($reqComplaint !== '') {
                $registration_chief_complaint = $reqComplaint;
                $chief_complaint_locked = true;
                $chief_complaint_source = $chief_complaint_source !== '' ? $chief_complaint_source : 'care_tips_review';
                $active_chief_complaint_triage_id = (int) $reqRow['id'];
            }
        } elseif ($active_chief_complaint_triage_id <= 0) {
            $active_chief_complaint_triage_id = $requested_triage_id;
        }
    } catch (Throwable $e) {
        if ($active_chief_complaint_triage_id <= 0) {
            $active_chief_complaint_triage_id = $requested_triage_id;
        }
    }
}
$portal_triage_urgency = (string) ($active_chief_complaint['urgency'] ?? '');
if ($portal_triage_urgency === '') {
    $portal_triage_urgency = (string) ($pending_reg['urgency'] ?? '');
}
if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
    triage_assessment_ensure_schema($pdo);
    $s = $pdo->prepare('SELECT id, level, symptoms, assessed_at, chief_complaint, urgency_label, triage_level, triage_classification, assessment_payload, outcome, recommendation_status FROM triage_results WHERE patient_id=? ORDER BY assessed_at DESC, id DESC');
    $s->execute([$uid]);
    $triage_history = $s->fetchAll(PDO::FETCH_ASSOC);
}

require_once BASE_PATH . '/app/includes/patient_booking_status.php';
foreach ($triage_history as &$triageHistoryRow) {
    $assessedAt = (string) ($triageHistoryRow['assessed_at'] ?? '');
    $triageHistoryRow['_booking_state'] = $assessedAt !== ''
        ? patient_triage_row_booking_state($pdo, (int) $uid, $assessedAt, (int) ($triageHistoryRow['id'] ?? 0))
        : 'none';
}
unset($triageHistoryRow);

$booking_providers = [];
if ($pdo->query("SHOW TABLES LIKE 'users'")->rowCount()) {
    $bp = $pdo->query("
        SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name
        FROM users u
        WHERE u.role = 'provider' AND u.is_active = 1
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $booking_providers = $bp ? $bp->fetchAll(PDO::FETCH_ASSOC) : [];
}

$review_booking_ctx = triage_patient_review_booking_context($pdo, (int) $uid);
if ($force_new_concern) {
    $review_booking_ctx = ['locked' => false, 'provider_id' => 0, 'provider_name' => '', 'triage_id' => 0];
}
$preliminary_complaint_triage = (empty($review_booking_ctx['locked']) && !$force_new_concern)
    ? patient_find_preliminary_complaint_triage($pdo, (int) $uid)
    : null;
$review_booking_slots = triage_patient_booking_slot_status($pdo, (int) $uid);
$locked_provider_id = (int) ($review_booking_ctx['provider_id'] ?? 0);
$locked_provider_name = trim((string) ($review_booking_ctx['provider_name'] ?? ''));
$locked_assigned_has_slots = !empty($review_booking_slots['assigned_has_slots_today']);
$locked_alternate_available = !empty($review_booking_slots['alternate_available']);
if ($locked_provider_id > 0 && $locked_provider_name === '') {
    $locked_provider_name = triage_provider_display_name($pdo, $locked_provider_id);
}
if (!empty($review_booking_ctx['locked']) && $locked_provider_id > 0) {
    $booking_providers = array_values(array_filter(
        $booking_providers,
        static fn(array $p): bool => (int) ($p['id'] ?? 0) === $locked_provider_id
    ));
    if ($booking_providers === [] && $locked_provider_id > 0) {
        $display = $locked_provider_name !== ''
            ? $locked_provider_name
            : triage_provider_display_name($pdo, $locked_provider_id);
        $booking_providers[] = ['id' => $locked_provider_id, 'name' => $display];
    }
}

// Normalize provider labels in dropdown (skip generic placeholder names).
foreach ($booking_providers as &$bpRow) {
    $pid = (int) ($bpRow['id'] ?? 0);
    if ($pid > 0) {
        $bpRow['name'] = triage_provider_display_name($pdo, $pid);
    }
}
unset($bpRow);

$all_consults = [];
if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
    $s = $pdo->prepare("
        SELECT c.id, c.consult_date, c.consult_time, c.provider_name, c.consult_type, c.status
        FROM consultations c
        WHERE c.patient_id = ?
        ORDER BY c.consult_date DESC, c.consult_time DESC
    ");
    $s->execute([$uid]);
    $all_consults = $s->fetchAll(PDO::FETCH_ASSOC);
}

require_once BASE_PATH . '/app/includes/patient_booking_status.php';
$active_consultation = null;
$future_scheduled_consultation = null;
foreach ($all_consults as $c) {
    if (!in_array($c['status'] ?? '', ['pending', 'scheduled', 'waiting', 'in_consultation'], true)) {
        continue;
    }
    if (($c['status'] ?? '') === 'in_consultation') {
        $active_consultation = $c;
        break;
    }
    if (consultation_is_future_day($c['consult_date'] ?? null)) {
        if ($future_scheduled_consultation === null) {
            $future_scheduled_consultation = $c;
        }
        continue;
    }
    if ($active_consultation === null) {
        $active_consultation = $c;
    }
}
// Prefer soonest same-day/open visit when multiple exist.
if ($active_consultation === null) {
    $active_consultation = patient_portal_select_active_consultation($all_consults);
    if ($active_consultation && consultation_is_future_day($active_consultation['consult_date'] ?? null)
        && strtolower((string) ($active_consultation['status'] ?? '')) !== 'in_consultation') {
        $future_scheduled_consultation = $future_scheduled_consultation ?? $active_consultation;
        $active_consultation = null;
    }
} else {
    $picked = patient_portal_select_active_consultation(array_values(array_filter(
        $all_consults,
        static fn(array $c): bool => in_array($c['status'] ?? '', ['pending', 'scheduled', 'waiting', 'in_consultation'], true)
            && !consultation_is_future_day($c['consult_date'] ?? null)
    )));
    if ($picked) {
        $active_consultation = $picked;
    }
}

$booking_blocked_in_consultation = ($active_consultation['status'] ?? '') === 'in_consultation';
$booking_blocked_future = false;
$booking_same_day_reschedule = !$booking_blocked_in_consultation
    && $active_consultation
    && !consultation_is_future_day($active_consultation['consult_date'] ?? null);
$booking_future_label = '';
if ($future_scheduled_consultation) {
    $booking_future_label = date('M j, Y', strtotime((string) $future_scheduled_consultation['consult_date']));
    if (!empty($future_scheduled_consultation['consult_time'])) {
        $booking_future_label .= ' at ' . date('g:i A', strtotime((string) $future_scheduled_consultation['consult_time']));
    }
} elseif ($booking_same_day_reschedule && !empty($active_consultation['consult_date'])) {
    $booking_future_label = date('M j, Y', strtotime((string) $active_consultation['consult_date']));
    if (!empty($active_consultation['consult_time'])) {
        $booking_future_label .= ' at ' . date('g:i A', strtotime((string) $active_consultation['consult_time']));
    }
}
$patient_has_scheduled_followup = patient_portal_has_scheduled_followup($pdo, (int) $uid);

$page_title = 'Book Consultation';
$patient_has_completed_visit = patient_portal_has_completed_visit($pdo, (int) $uid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

    <div class="patient-page patient-triage-page">
      <?php require VIEWS_PATH . '/patient/partials/view_triage.php'; ?>
    </div>

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

  <script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
  <script>window.BOOKING_BLOCKED_IN_CONSULTATION = <?= json_encode($booking_blocked_in_consultation) ?>;</script>
  <script>window.BOOKING_BLOCKED_FUTURE_APPOINTMENT = <?= json_encode(false) ?>;</script>
  <script>window.BOOKING_FUTURE_APPOINTMENT_LABEL = <?= json_encode($booking_future_label) ?>;</script>
  <script>window.PATIENT_HAS_SCHEDULED_FOLLOWUP = <?= json_encode($patient_has_scheduled_followup) ?>;</script>
  <script>window.REGISTRATION_URGENCY = <?= json_encode($portal_triage_urgency) ?>;</script>
  <script>window.BOOKING_LOCKED_PROVIDER_ID = <?= json_encode($locked_provider_id > 0 ? $locked_provider_id : null) ?>;</script>
  <script>window.BOOKING_LOCKED_PROVIDER_NAME = <?= json_encode($locked_provider_name) ?>;</script>
  <script>window.BOOKING_ASSIGNED_HAS_SLOTS = <?= json_encode($locked_assigned_has_slots) ?>;</script>
  <script>window.BOOKING_ALTERNATE_AVAILABLE = <?= json_encode($locked_alternate_available) ?>;</script>
  <script>window.TRIAGE_REVIEW_FIRST_ALLOWED = <?= json_encode(empty($review_booking_ctx['locked'])) ?>;</script>
  <script>window.ACTIVE_CHIEF_COMPLAINT_TRIAGE_ID = <?= json_encode($active_chief_complaint_triage_id > 0 ? $active_chief_complaint_triage_id : null) ?>;</script>
  <script>window.REGISTRATION_COMPLAINT_REFERENCE = <?= json_encode($registration_chief_complaint) ?>;</script>
  <?php if ($portal_triage_urgency === 'EMERGENCY'): ?>
  <script>
  try { sessionStorage.setItem('medconnect_block_telemedicine', '1'); } catch (_) {}
  </script>
  <?php endif; ?>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-portal.js?v=<?= $patient_portal_ver ?>"></script>
  <?php $visitHistoryJsVer = (int) @filemtime(ASSETS_PATH . '/js/patient-visit-history.js'); ?>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-visit-history.js?v=<?= $visitHistoryJsVer ?>"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.refreshBookingPicker === 'function') {
      window.refreshBookingPicker();
    }
  });
  </script>
</body>
</html>
