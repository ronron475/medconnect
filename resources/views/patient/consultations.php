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
require_once BASE_PATH . '/app/includes/urgent_followup_workflow.php';
require_once BASE_PATH . '/app/includes/appointment_reschedule.php';
require_once BASE_PATH . '/app/includes/appointment_schedule_schema.php';
appointment_schedule_ensure_schema($pdo);

$all_consults = [];
$followup_eligible_ids = [];
$followup_eligible_map = [];
$pending_reschedules = appointment_reschedule_pending_for_patient($pdo, $uid);
$pending_reschedule_by_consult = [];
foreach ($pending_reschedules as $pr) {
    $pending_reschedule_by_consult[(int) ($pr['consultation_id'] ?? 0)] = $pr;
}

if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
    $s = $pdo->prepare("
        SELECT c.id, c.consult_date, c.consult_time, c.provider_id, c.provider_name, c.consult_type, c.status, c.diagnosis, c.recommendation,
               c.original_consult_date, c.original_consult_time, c.reschedule_status,
               vs.room_token,
               s.slot_date, s.start_time AS slot_start
        FROM consultations c
        LEFT JOIN video_sessions vs ON c.id = vs.consultation_id AND vs.status = 'active'
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status IN ('booked', 'blocked')
        WHERE c.patient_id = ?
        ORDER BY c.consult_date DESC, c.consult_time DESC
    ");
    $s->execute([$uid]);
    $all_consults = $s->fetchAll(PDO::FETCH_ASSOC);

    foreach ($all_consults as &$consult) {
        $cid = (int) ($consult['id'] ?? 0);
        if (isset($pending_reschedule_by_consult[$cid])) {
            $consult['pending_reschedule'] = $pending_reschedule_by_consult[$cid];
        }
    }
    unset($consult);

    $eligible = urgent_followup_eligible_consultations($pdo, $uid);
    foreach ($eligible as $row) {
        $cid = (int) ($row['id'] ?? 0);
        if ($cid > 0) {
            $followup_eligible_ids[] = $cid;
            $followup_eligible_map[$cid] = $row['previous_chief_complaint'] ?? '';
        }
    }
    foreach ($all_consults as &$consult) {
        $cid = (int) ($consult['id'] ?? 0);
        if (isset($followup_eligible_map[$cid])) {
            $consult['followup_eligible'] = true;
            $consult['previous_chief_complaint'] = $followup_eligible_map[$cid];
        }
    }
    unset($consult);
}

// Patients can cancel pending/scheduled visits anytime (frees the appointment slot).
$can_cancel_visits = true;

$page_title = 'My Sessions';
$sessions_css_ver = (int) @filemtime(ASSETS_PATH . '/css/patient-sessions.css');
$patient_page_stylesheets = [
    ASSET_BASE . '/assets/css/patient-sessions.css?v=' . $sessions_css_ver,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

    <div class="patient-page">
      <?php require VIEWS_PATH . '/patient/partials/view_consultations.php'; ?>
    </div>

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

  <script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
  <script>window.CAN_CANCEL_AFTER_TIPS_APPROVED = <?= json_encode($can_cancel_visits) ?>;</script>
  <script>window.consultations = <?= json_encode($all_consults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script>
  <script>window.followupEligibleIds = <?= json_encode($followup_eligible_ids, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;</script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-portal.js?v=<?= $patient_portal_ver ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-followup.js?v=<?= (int) @filemtime(ASSETS_PATH . '/js/patient-followup.js') ?>"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.filterSessions === 'function') {
      window.filterSessions('upcoming');
    }
  });
  </script>
</body>
</html>
