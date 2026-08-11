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
require_once BASE_PATH . '/app/includes/consultation_expiry.php';
require_once BASE_PATH . '/app/includes/profile_picture.php';
require_once BASE_PATH . '/app/includes/patient_portal_bootstrap.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';
require_once BASE_PATH . '/app/includes/patient_symptoms_review_submit.php';
require_once BASE_PATH . '/app/includes/patient_chief_complaints.php';

$booking_today_ymd   = date('Y-m-d');
$booking_today_label = date('l, M j, Y');

// ── DATA FETCHING: Patient Profile ───────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        u.id                                        AS user_id,
        u.first_name,
        u.last_name,
        u.email,
        u.profile_picture,
        COALESCE(p.contact_number, '')              AS contact_number,
        COALESCE(p.age, '')                         AS age,
        COALESCE(p.gender, '')                      AS gender,
        COALESCE(p.date_of_birth, '')               AS date_of_birth,
        COALESCE(p.blood_type, '')                  AS blood_type,
        COALESCE(p.philhealth_status, '')           AS philhealth_status,
        COALESCE(p.region, '')                      AS region,
        COALESCE(p.province, '')                    AS province,
        COALESCE(p.city_municipality, '')           AS city_municipality,
        COALESCE(p.barangay, '')                    AS barangay,
        COALESCE(p.status, 'pending')               AS reg_status,
        COALESCE(p.emergency_contact_name, '')      AS emergency_contact_name,
        COALESCE(p.emergency_contact_phone, '')     AS emergency_contact_phone,
        COALESCE(p.emergency_contact_relation, '')  AS emergency_contact_relation,
        CONCAT('MC-', LPAD(u.id, 6, '0'))           AS patient_number
    FROM users u
    LEFT JOIN patient_registrations p ON p.email = u.email
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$uid]);
$pt = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$patient_initials = profile_picture_initials($pt['first_name'] ?? '', $pt['last_name'] ?? '');
$patient_picture_url = profile_picture_public_url($pt['profile_picture'] ?? $_SESSION['profile_picture'] ?? null);

// Compute full address for display
$pt['full_address'] = implode(', ', array_filter([
    $pt['barangay'] ?? '',
    $pt['city_municipality'] ?? '',
    $pt['province'] ?? '',
]));
if (empty($pt['full_address'])) $pt['full_address'] = '';

// ── DATA FETCHING: Triage History ────────────────────────────────────────────
$triage_history = [];
if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
    $s = $pdo->prepare("SELECT level, symptoms, assessed_at, chief_complaint, urgency_label FROM triage_results WHERE patient_id=? ORDER BY assessed_at DESC");
    $s->execute([$uid]);
    $triage_history = $s->fetchAll(PDO::FETCH_ASSOC);
}
$latest_triage = !empty($triage_history) ? $triage_history[0] : null;

// ── DATA FETCHING: Active providers for schedule-based booking ───────────────
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

// ── DATA FETCHING: Consultations ─────────────────────────────────────────────
$all_consults = [];
if ($pdo->query("SHOW TABLES LIKE 'consultations'")->rowCount()) {
    $s = $pdo->prepare("
        SELECT c.id, c.consult_date, c.consult_time, c.provider_name, c.consult_type, c.status, c.diagnosis, c.recommendation,
               vs.room_token,
               s.slot_date, s.start_time AS slot_start
        FROM consultations c
        LEFT JOIN video_sessions vs ON c.id = vs.consultation_id AND vs.status = 'active'
        LEFT JOIN appointment_slots s ON s.consultation_id = c.id AND s.status = 'booked'
        WHERE c.patient_id=? 
        ORDER BY c.consult_date DESC, c.consult_time DESC
    ");
    $s->execute([$uid]);
    $all_consults = $s->fetchAll(PDO::FETCH_ASSOC);
}

$upcoming_consults = array_filter($all_consults, function($c) {
    return $c['status'] !== 'cancelled' && strtotime($c['consult_date']) >= strtotime(date('Y-m-d'));
});

require_once BASE_PATH . '/app/includes/patient_booking_status.php';
require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
require_once BASE_PATH . '/app/includes/clinical_tables.php';
clinical_tables_ensure($pdo);
patient_consultation_records_schema_ensure($pdo);
$active_consultation = patient_portal_select_active_consultation($all_consults);

// ── DATA FETCHING: Clinical Records (prescriptions + notes from consultations) ─
// Pulls real rows from prescriptions and clinical_notes joined to consultations.
$health_files = [];
try {
    // Prescriptions issued to this patient
    $s = $pdo->prepare("
        SELECT
            CONCAT(pr.medication_name, ' ', pr.dosage) AS name,
            DATE(pr.created_at)                         AS file_date,
            CONCAT(u.first_name, ' ', u.last_name)      AS doctor,
            'Prescription'                              AS record_type,
            pr.frequency,
            pr.duration,
            pr.notes                                    AS detail
        FROM prescriptions pr
        JOIN users u ON u.id = pr.provider_id
        WHERE pr.patient_id = ?
        ORDER BY pr.created_at DESC
        LIMIT 20
    ");
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $health_files[] = $row;
    }

    // Clinical notes from finalized consultations only
    $s = $pdo->prepare("
        SELECT
            COALESCE(NULLIF(cn.diagnosis, ''), 'Clinical Note') AS name,
            DATE(cn.created_at)                                  AS file_date,
            CONCAT(u.first_name, ' ', u.last_name)               AS doctor,
            'Clinical Note'                                      AS record_type,
            cn.assessment                                        AS frequency,
            cn.plan                                              AS duration,
            cn.treatment_plan                                    AS detail
        FROM clinical_notes cn
        JOIN consultations c ON c.id = cn.consultation_id
        JOIN users u ON u.id = cn.provider_id
        WHERE cn.patient_id = ?
          AND " . patient_consultation_record_visible_sql('c', 'cn') . "
        ORDER BY cn.created_at DESC
        LIMIT 20
    ");
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $health_files[] = $row;
    }

    // Referrals issued to this patient
    $s = $pdo->prepare("
        SELECT
            CONCAT(dr.referral_type, ' Referral') AS name,
            DATE(dr.created_at)                    AS file_date,
            CONCAT(u.first_name, ' ', u.last_name) AS doctor,
            'Referral'                             AS record_type,
            dr.reason                              AS frequency,
            dr.destination_facility                AS duration,
            dr.status                              AS detail
        FROM digital_referrals dr
        JOIN users u ON u.id = dr.provider_id
        WHERE dr.patient_id = ?
        ORDER BY dr.created_at DESC
        LIMIT 20
    ");
    $s->execute([$uid]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $health_files[] = $row;
    }

    // Sort all records newest-first
    usort($health_files, fn($a, $b) => strcmp($b['file_date'] ?? '', $a['file_date'] ?? ''));

} catch (PDOException $e) {
    // Tables may not exist yet in a fresh install — silently skip
    $health_files = [];
}

// Record type counts for folder summary
$record_counts = array_count_values(array_column($health_files, 'record_type'));

$dash_upcoming_count = count($upcoming_consults);
$dash_total_sessions = count($all_consults);
$dash_triage_count   = count($triage_history);
$dash_records_count  = count($health_files);
$dash_greeting_date  = date('l, F j, Y');

$dash_hour = (int) date('G');
$dash_greeting_time = $dash_hour < 12 ? 'Good morning' : ($dash_hour < 17 ? 'Good afternoon' : 'Good evening');

$dash_med_count = 0;
try {
    if ($pdo->query("SHOW TABLES LIKE 'prescriptions'")->rowCount()) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM prescriptions WHERE patient_id = ?');
        $s->execute([$uid]);
        $dash_med_count = (int) $s->fetchColumn();
    }
} catch (PDOException $e) {
    $dash_med_count = 0;
}

$pt['allergies'] = $pt['allergies'] ?? '';
try {
    if ($pdo->query("SHOW COLUMNS FROM patient_registrations LIKE 'allergies'")->rowCount()) {
        $s = $pdo->prepare('SELECT allergies FROM patient_registrations WHERE email = ? LIMIT 1');
        $s->execute([$pt['email'] ?? '']);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['allergies'])) {
            $pt['allergies'] = $row['allergies'];
        }
    }
} catch (PDOException $e) {
    // optional column
}

$page_title = 'Health Dashboard';
require_once __DIR__ . '/partials/triage_helpers.php';

$video_base = ASSET_BASE . '/views/consultation/video_room.php';
require_once VIEWS_PATH . '/provider/partials/queue_helpers.php';

$dash_today_appts = 0;
$dash_in_consultation = 0;
$dash_completed_month = 0;
$month_start = date('Y-m-d', strtotime('first day of this month'));
foreach ($all_consults as $c) {
    $d = (string) ($c['consult_date'] ?? '');
    $st = strtolower((string) ($c['status'] ?? ''));
    if ($d === date('Y-m-d') && !in_array($st, ['cancelled', 'canceled'], true)) {
        $dash_today_appts++;
    }
    if ($st === 'in_consultation') {
        $dash_in_consultation++;
    }
    if ($st === 'completed' && $d >= $month_start) {
        $dash_completed_month++;
    }
}

$dash_urgent_triage = 0;
foreach ($triage_history as $t) {
    $risk = mc_triage_risk_class((string) ($t['level'] ?? ''));
    if (in_array($risk, ['badge-risk--high', 'badge-risk--moderate'], true)) {
        $dash_urgent_triage++;
    }
}

$symptoms_review_pending = patient_symptoms_review_pending_state($pdo, (int) $uid);
$care_tips_ready_to_schedule = patient_care_tips_ready_to_schedule_state($pdo, (int) $uid);
$symptoms_review_booking = triage_patient_booking_slot_status($pdo, (int) $uid);
$pending_reg_complaint = patient_registration_load_pending_complaint($pdo, (int) $uid);
$active_chief_complaint = patient_portal_active_chief_complaint($pdo, (int) $uid);
$registration_chief_complaint = trim((string) ($active_chief_complaint['complaint'] ?? ''));
$chief_complaint_locked = !empty($active_chief_complaint['locked']) && $registration_chief_complaint !== '';
$chief_complaint_source = (string) ($active_chief_complaint['source'] ?? '');
$active_chief_complaint_triage_id = (int) ($active_chief_complaint['triage_id'] ?? 0);
$portal_triage_urgency = (string) ($active_chief_complaint['urgency'] ?? '');
if ($portal_triage_urgency === '') {
    $portal_triage_urgency = (string) ($pending_reg_complaint['urgency'] ?? '');
}
$show_dashboard_care_tips_section = patient_dashboard_show_care_tips_section(
    $pending_reg_complaint,
    $symptoms_review_pending,
    $care_tips_ready_to_schedule
);
$dash_chief_complaint_url = !empty($symptoms_review_pending['has_pending'])
    ? '#pdashSymptomsReview'
    : (!empty($care_tips_ready_to_schedule['ready'])
        ? '#pdashCareTipsReady'
        : '#pdashChiefComplaint');

$patient_followups = [];
if ($pdo->query("SHOW TABLES LIKE 'followups'")->rowCount()) {
    $fu = $pdo->prepare("
        SELECT f.*, u.first_name AS provider_first, u.last_name AS provider_last
        FROM followups f
        JOIN users u ON f.provider_id = u.id
        WHERE f.patient_id = ?
        ORDER BY
          CASE LOWER(COALESCE(f.status, 'scheduled'))
            WHEN 'scheduled' THEN 0
            WHEN 'completed' THEN 1
            WHEN 'missed' THEN 2
            ELSE 3
          END,
          f.followup_date ASC,
          f.id DESC
    ");
    $fu->execute([$uid]);
    $patient_followups = $fu->fetchAll(PDO::FETCH_ASSOC);
}

$week_chart = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $count = 0;
    foreach ($all_consults as $c) {
        if (($c['consult_date'] ?? '') === $date) {
            $count++;
        }
    }
    $week_chart[] = [
        'label'    => date('D', strtotime($date)),
        'date'     => date('M j', strtotime($date)),
        'count'    => $count,
        'is_today' => ($i === 0),
    ];
}
$chart_max = max(1, ...array_column($week_chart, 'count'));
$week_total = array_sum(array_column($week_chart, 'count'));

$pt_last_name = trim($pt['last_name'] ?? 'Patient');

$pt_dash_status_class = static function (?string $status): string {
    $s = strtolower(trim($status ?? ''));
    if (in_array($s, ['completed', 'done'], true)) {
        return 'pt-status--completed';
    }
    if (in_array($s, ['in_consultation', 'active'], true)) {
        return 'pt-status--live';
    }
    if (in_array($s, ['cancelled', 'canceled'], true)) {
        return 'pt-status--cancelled';
    }
    if ($s === 'scheduled') {
        return 'pt-status--scheduled';
    }
    return 'pt-status--pending';
};

$pt_dash_triage_skin = static function (?string $level): string {
    $risk = mc_triage_risk_class((string) $level);
    if ($risk === 'badge-risk--high') {
        return 'pt-dash-triage--high';
    }
    if ($risk === 'badge-risk--moderate') {
        return 'pt-dash-triage--moderate';
    }
    return '';
};
$patientDashCssVer = (int) @filemtime(ASSETS_PATH . '/css/patient-dashboard.css');
$patient_page_stylesheets = [
    ASSET_BASE . '/assets/css/patient-dashboard.css?v=' . $patientDashCssVer,
    ASSET_BASE . '/assets/css/medconnect-charts.css?v=' . (int) @filemtime(ASSETS_PATH . '/css/medconnect-charts.css'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

      <?php require_once VIEWS_PATH . '/patient/partials/dashboard_home.php'; ?>

  <?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

  <script>window.APP_BASE = <?= json_encode(ASSET_BASE) ?>;</script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/medconnect-chart-theme.js?v=<?= (int) @filemtime(ASSETS_PATH . '/js/medconnect-chart-theme.js') ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/medconnect-portal-charts.js?v=<?= (int) @filemtime(ASSETS_PATH . '/js/medconnect-portal-charts.js') ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-portal.js?v=<?= $patient_portal_ver ?>"></script>
  <script src="<?= ASSET_BASE ?>/assets/js/patient-symptoms-review.js?v=<?= (int) @filemtime(ASSETS_PATH . '/js/patient-symptoms-review.js') ?>"></script>
  <script>
  (function () {
    const cells = document.querySelectorAll('[data-consult-action]');
    if (!cells.length) return;
    const base = window.APP_BASE || '';
    const videoBase = <?= json_encode($video_base) ?>;
    const unlockTimers = new Map();

    function schedulePatientUnlock(item) {
      if (!item || !item.id || !item.scheduled_start || item.join_allowed) return;
      if (unlockTimers.has(item.id)) return;
      const delay = (item.scheduled_start * 1000) - Date.now() + 800;
      if (delay <= 0) return;
      const timer = window.setTimeout(() => {
        unlockTimers.delete(item.id);
        refreshDashJoinButtons();
      }, Math.min(delay, 24 * 60 * 60 * 1000));
      unlockTimers.set(item.id, timer);
    }

    async function refreshDashJoinButtons() {
      try {
        const res = await fetch(base + '/app/api/consultations/consultation_status.php?_=' + Date.now(), {
          credentials: 'same-origin',
          cache: 'no-store',
        });
        const json = await res.json();
        if (!json || !json.success || !Array.isArray(json.items)) return;
        const byId = {};
        json.items.forEach((item) => { byId[String(item.id)] = item; });

        cells.forEach((cell) => {
          const id = cell.getAttribute('data-consult-action');
          const item = byId[id];
          if (!item) return;
          if (item.join_allowed && item.room_token) {
            const safeToken = String(item.room_token).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
            cell.innerHTML =
              '<button type="button" class="pdash-btn pdash-btn--join pdash-btn--sm" data-mc-video-join data-token="' +
              safeToken + '" data-consultation-id="' + String(item.id || '') +
              '">Join Call</button>';
          } else if (item.join_mode === 'scheduled_wait') {
            const opens = item.opens_at_label ? ('Opens at ' + item.opens_at_label) : 'Opens at scheduled time';
            cell.innerHTML =
              '<span class="pdash-btn pdash-btn--waiting pdash-btn--sm" style="cursor:default;">' + opens + '</span>';
          } else if (item.join_mode === 'waiting') {
            cell.innerHTML =
              '<span class="pdash-btn pdash-btn--waiting pdash-btn--sm consult-waiting-pulse" style="cursor:default;">Waiting for Provider</span>';
          }
        });

        json.items.forEach(schedulePatientUnlock);
      } catch (_) {}
    }

    refreshDashJoinButtons();
    setInterval(refreshDashJoinButtons, 5000);

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refreshDashJoinButtons();
    });

    if (window.location.hash === '#action-items') {
      const el = document.getElementById('dashboardActionItems');
      if (el) setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
    }

    function pdashStyleEmergencyWidget() {
      const widget = document.getElementById('pdashEmergencyWidget');
      if (!widget) return;
      const val = widget.querySelector('.pdash-alert-widget__value');
      if (val && parseInt(val.textContent, 10) > 0) {
        widget.classList.add('is-active');
      }
    }
    setTimeout(pdashStyleEmergencyWidget, 600);
    setInterval(pdashStyleEmergencyWidget, 30000);
  })();
  </script>
</body>
</html>
