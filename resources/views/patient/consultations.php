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
require_once BASE_PATH . '/app/includes/clinical_tables.php';
require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
appointment_schedule_ensure_schema($pdo);
clinical_tables_ensure($pdo);
patient_consultation_records_schema_ensure($pdo);

$all_consults = [];
$followup_eligible_ids = [];
$followup_eligible_map = [];
$pending_reschedules = appointment_reschedule_pending_for_patient($pdo, $uid);
$pending_reschedule_by_consult = [];
foreach ($pending_reschedules as $pr) {
    $pending_reschedule_by_consult[(int) ($pr['consultation_id'] ?? 0)] = $pr;
}

if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
    $consultCols = [];
    try {
        $consultCols = $pdo->query('SHOW COLUMNS FROM consultations')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $consultCols = [];
    }
    $hasTriageLink = in_array('triage_result_id', $consultCols, true);
    $hasCompletedAt = in_array('completed_at', $consultCols, true);
    $triageSelect = $hasTriageLink ? 'c.triage_result_id,' : 'NULL AS triage_result_id,';
    $completedSelect = $hasCompletedAt ? 'c.completed_at,' : 'NULL AS completed_at,';

    $s = $pdo->prepare("
        SELECT c.id, c.consult_date, c.consult_time, c.provider_id, c.provider_name, c.consult_type, c.status,
               c.original_consult_date, c.original_consult_time, c.reschedule_status,
               {$triageSelect}
               {$completedSelect}
               vs_active.room_token,
               vs_last.status AS video_status,
               vs_last.started_at AS video_started_at,
               vs_last.ended_at AS video_ended_at,
               s.slot_date, s.start_time AS slot_start
        FROM consultations c
        LEFT JOIN video_sessions vs_active ON vs_active.consultation_id = c.id AND vs_active.status = 'active'
        LEFT JOIN video_sessions vs_last ON vs_last.id = (
            SELECT vs2.id
            FROM video_sessions vs2
            WHERE vs2.consultation_id = c.id
            ORDER BY vs2.id DESC
            LIMIT 1
        )
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
        $consult['provider_name'] = patient_provider_display_name((string) ($consult['provider_name'] ?? ''));
        $consult['chief_complaint'] = patient_session_chief_complaint($pdo, (int) $uid, $consult);
        $consult['duration_label'] = patient_format_call_duration(
            (string) ($consult['video_started_at'] ?? ''),
            (string) ($consult['video_ended_at'] ?? '')
        );
        unset($consult['triage_result_id']);
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
  <?php
    $sessions_tab = strtolower(trim((string) ($_GET['tab'] ?? 'upcoming')));
    if (!in_array($sessions_tab, ['upcoming', 'active', 'past'], true)) {
        $sessions_tab = 'upcoming';
    }
  ?>
  <script>window.SESSIONS_DEFAULT_TAB = <?= json_encode($sessions_tab) ?>;</script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-portal.js?v=<?= $patient_portal_ver ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-followup.js?v=<?= (int) @filemtime(ASSETS_PATH . '/js/patient-followup.js') ?>"></script>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    if (typeof window.filterSessions === 'function') {
      window.filterSessions(window.SESSIONS_DEFAULT_TAB || 'upcoming');
    }
  });
  </script>
</body>
</html>
