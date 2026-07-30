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

$booking_today_ymd   = date('Y-m-d');
$booking_today_label = date('l, M j, Y');

$triage_history = [];
$default_complaint = '';
$pending_reg = patient_registration_load_pending_complaint($pdo, (int) $uid);
if ($pending_reg['complaint'] !== '') {
    $default_complaint = $pending_reg['complaint'];
}
if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
    $s = $pdo->prepare('SELECT level, symptoms, assessed_at, chief_complaint, urgency_label, triage_level FROM triage_results WHERE patient_id=? ORDER BY assessed_at DESC');
    $s->execute([$uid]);
    $triage_history = $s->fetchAll(PDO::FETCH_ASSOC);
    if ($default_complaint === '' && !empty($triage_history[0]['chief_complaint'])) {
        $default_complaint = (string) $triage_history[0]['chief_complaint'];
    }
}

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

$active_consultation = null;
foreach ($all_consults as $c) {
    if (in_array($c['status'] ?? '', ['pending', 'scheduled', 'in_consultation'], true)) {
        $active_consultation = $c;
        break;
    }
}

$booking_blocked_in_consultation = ($active_consultation['status'] ?? '') === 'in_consultation';
$booking_blocked_future = !$booking_blocked_in_consultation
    && $active_consultation
    && consultation_is_future_day($active_consultation['consult_date'] ?? null);
$booking_future_label = '';
if ($booking_blocked_future) {
    $booking_future_label = date('M j, Y', strtotime((string) $active_consultation['consult_date']));
    if (!empty($active_consultation['consult_time'])) {
        $booking_future_label .= ' at ' . date('g:i A', strtotime((string) $active_consultation['consult_time']));
    }
}

$page_title = 'Book Consultation';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

    <div class="patient-page">
      <?php require VIEWS_PATH . '/patient/partials/view_triage.php'; ?>
    </div>

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

  <script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
  <script>window.BOOKING_BLOCKED_IN_CONSULTATION = <?= json_encode($booking_blocked_in_consultation) ?>;</script>
  <script>window.BOOKING_BLOCKED_FUTURE_APPOINTMENT = <?= json_encode($booking_blocked_future) ?>;</script>
  <script>window.BOOKING_FUTURE_APPOINTMENT_LABEL = <?= json_encode($booking_future_label) ?>;</script>
  <script>window.REGISTRATION_URGENCY = <?= json_encode($pending_reg['urgency'] ?? '') ?>;</script>
  <script>window.BOOKING_LOCKED_PROVIDER_ID = <?= json_encode($locked_provider_id > 0 ? $locked_provider_id : null) ?>;</script>
  <script>window.BOOKING_LOCKED_PROVIDER_NAME = <?= json_encode($locked_provider_name) ?>;</script>
  <script>window.BOOKING_ASSIGNED_HAS_SLOTS = <?= json_encode($locked_assigned_has_slots) ?>;</script>
  <script>window.BOOKING_ALTERNATE_AVAILABLE = <?= json_encode($locked_alternate_available) ?>;</script>
  <script>window.TRIAGE_REVIEW_FIRST_ALLOWED = <?= json_encode(true) ?>;</script>
  <?php if (($pending_reg['nlp_json'] ?? '') !== ''): ?>
  <script>
  try {
    if (!sessionStorage.getItem('medconnect_pending_nlp_result')) {
      sessionStorage.setItem('medconnect_pending_nlp_result', <?= json_encode($pending_reg['nlp_json']) ?>);
    }
  } catch (_) {}
  </script>
  <?php endif; ?>
  <?php if (($pending_reg['urgency'] ?? '') === 'EMERGENCY'): ?>
  <script>
  try { sessionStorage.setItem('medconnect_block_telemedicine', '1'); } catch (_) {}
  </script>
  <?php endif; ?>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-portal.js?v=<?= $patient_portal_ver ?>"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.refreshBookingPicker === 'function') {
      window.refreshBookingPicker();
    }
  });
  </script>
</body>
</html>
