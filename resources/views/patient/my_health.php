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
require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
require_once BASE_PATH . '/app/includes/clinical_tables.php';

clinical_tables_ensure($pdo);
patient_consultation_records_schema_ensure($pdo);

$uid = (int) $uid;
$tab = (string) ($_GET['tab'] ?? 'timeline');
$active_tab = in_array($tab, ['files', 'care-tips'], true) ? $tab : 'timeline';

$stmt = $pdo->prepare("
    SELECT u.first_name, u.last_name, CONCAT('MC-', LPAD(u.id, 6, '0')) AS patient_number
    FROM users u WHERE u.id = ? LIMIT 1
");
$stmt->execute([$uid]);
$pt = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$consults = $pdo->prepare("
    SELECT c.*, u.first_name, u.last_name
    FROM consultations c
    JOIN users u ON c.provider_id = u.id
    WHERE c.patient_id = ?
    ORDER BY c.consult_date DESC, c.consult_time DESC
");
$consults->execute([$uid]);
$history = $consults->fetchAll(PDO::FETCH_ASSOC);

$rx_by_consult = [];
$notes_by_consult = [];
if (!empty($history)) {
    $ids = array_map('intval', array_column($history, 'id'));
    $ids = array_filter($ids);
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $rxStmt = $pdo->prepare("
                SELECT p.consultation_id, p.medication_name, p.dosage, p.frequency
                FROM prescriptions p
                JOIN consultations c ON c.id = p.consultation_id
                WHERE p.consultation_id IN ($placeholders)
                  AND c.patient_id = ?
                  AND c.status = 'completed'
            ");
            $rxStmt->execute(array_merge(array_values($ids), [$uid]));
            while ($row = $rxStmt->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) ($row['consultation_id'] ?? 0);
                $rx_by_consult[$cid][] = $row;
            }
        } catch (PDOException $e) { /* optional */ }
        try {
            $cnStmt = $pdo->prepare("
                SELECT cn.consultation_id, cn.subjective, cn.objective, cn.assessment, cn.plan,
                       cn.diagnosis, cn.treatment_plan, cn.created_at
                FROM clinical_notes cn
                JOIN consultations c ON c.id = cn.consultation_id
                WHERE cn.consultation_id IN ($placeholders)
                  AND c.patient_id = ?
                  AND " . patient_consultation_record_visible_sql('c', 'cn') . "
            ");
            $cnStmt->execute(array_merge(array_values($ids), [$uid]));
            while ($row = $cnStmt->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) ($row['consultation_id'] ?? 0);
                $notes_by_consult[$cid] = $row;
            }
        } catch (PDOException $e) { /* optional */ }
    }
}

$prescriptions = [];
$clinical_notes = [];
$referrals = [];
try {
    $s = $pdo->prepare("
        SELECT CONCAT(pr.medication_name, ' ', pr.dosage) AS record_name, pr.frequency, pr.duration,
               COALESCE(pr.notes, '') AS detail, DATE(pr.created_at) AS record_date,
               CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM prescriptions pr
        JOIN consultations c ON c.id = pr.consultation_id
        JOIN users u ON u.id = pr.provider_id
        WHERE pr.patient_id = ? AND c.status = 'completed'
        ORDER BY pr.created_at DESC
    ");
    $s->execute([$uid]);
    $prescriptions = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

try {
    $s = $pdo->prepare("
        SELECT COALESCE(NULLIF(cn.diagnosis, ''), 'Clinical Note') AS record_name,
               cn.assessment AS frequency,
               cn.plan AS duration,
               COALESCE(NULLIF(cn.treatment_plan, ''), NULLIF(cn.subjective, ''), '') AS detail,
               cn.subjective, cn.objective, cn.assessment, cn.plan, cn.diagnosis, cn.treatment_plan,
               DATE(cn.created_at) AS record_date,
               CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM clinical_notes cn
        JOIN consultations c ON c.id = cn.consultation_id
        JOIN users u ON u.id = cn.provider_id
        WHERE cn.patient_id = ?
          AND " . patient_consultation_record_visible_sql('c', 'cn') . "
        ORDER BY cn.created_at DESC
    ");
    $s->execute([$uid]);
    $clinical_notes = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

try {
    $s = $pdo->prepare("
        SELECT CONCAT(dr.referral_type, ' Referral') AS record_name, dr.reason AS frequency,
               COALESCE(dr.destination_facility, '') AS duration, dr.status AS detail,
               DATE(dr.created_at) AS record_date, CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM digital_referrals dr
        JOIN consultations c ON c.id = dr.consultation_id
        JOIN users u ON u.id = dr.provider_id
        WHERE dr.patient_id = ? AND c.status = 'completed'
        ORDER BY dr.created_at DESC
    ");
    $s->execute([$uid]);
    $referrals = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

$all_records = [];
foreach ($prescriptions as $r) { $r['record_type'] = 'Prescription'; $all_records[] = $r; }
foreach ($clinical_notes as $r) { $r['record_type'] = 'Clinical Note'; $all_records[] = $r; }
foreach ($referrals as $r) { $r['record_type'] = 'Referral'; $all_records[] = $r; }
usort($all_records, fn($a, $b) => strcmp($b['record_date'] ?? '', $a['record_date'] ?? ''));

$counts = [
    'Prescription'  => count($prescriptions),
    'Clinical Note' => count($clinical_notes),
    'Referral'      => count($referrals),
    'all'           => count($all_records),
];

$completed_visits = count(array_filter($history, fn($h) => ($h['status'] ?? '') === 'completed'));

triage_assessment_ensure_schema($pdo);
require_once BASE_PATH . '/app/includes/patient_booking_status.php';
$care_tips_history = [];
$care_tips_active_count = 0;
if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
    $ct = $pdo->prepare("
        SELECT tr.id, tr.chief_complaint, tr.recommendations, tr.recommendation_status,
               tr.recommendation_approved_at, tr.recommendation_patient_ack_at, tr.assessed_at,
               tr.assigned_provider_id, tr.recommendation_approved_by,
               TRIM(CONCAT(reviewer.first_name, ' ', reviewer.last_name)) AS reviewer_name,
               TRIM(CONCAT(assignee.first_name, ' ', assignee.last_name)) AS assigned_name
        FROM triage_results tr
        LEFT JOIN users reviewer ON reviewer.id = tr.recommendation_approved_by
        LEFT JOIN users assignee ON assignee.id = tr.assigned_provider_id
        WHERE tr.patient_id = ?
          AND TRIM(COALESCE(tr.chief_complaint, '')) <> ''
          AND TRIM(COALESCE(tr.recommendations, '')) <> ''
          AND tr.recommendation_status IN ('pending_approval', 'approved', 'rejected', 'hidden')
        ORDER BY COALESCE(tr.recommendation_approved_at, tr.assessed_at) DESC, tr.id DESC
    ");
    $ct->execute([$uid]);
    $care_tips_history = $ct->fetchAll(PDO::FETCH_ASSOC);
    foreach ($care_tips_history as &$careTipsRow) {
        $assessedAt = (string) ($careTipsRow['assessed_at'] ?? '');
        $careTipsRow['_booking_state'] = $assessedAt !== ''
            ? patient_triage_row_booking_state($pdo, (int) $uid, $assessedAt, (int) ($careTipsRow['id'] ?? 0))
            : 'none';
    }
    unset($careTipsRow);
    require_once VIEWS_PATH . '/patient/partials/triage_helpers.php';
    foreach ($care_tips_history as $ctRow) {
        $meta = mc_patient_care_tip_meta($ctRow);
        if (!empty($meta['active'])) {
            $care_tips_active_count++;
        }
    }
}

$care_tips_pending_count = 0;
$care_tips_ready_count = 0;
$care_tips_completed_count = 0;
foreach ($care_tips_history as $ctRow) {
    $k = mc_patient_care_tip_meta($ctRow)['kind'] ?? '';
    if ($k === 'pending') {
        $care_tips_pending_count++;
    } elseif ($k === 'ready') {
        $care_tips_ready_count++;
    } elseif ($k === 'acked' || $k === 'rejected' || $k === 'historical') {
        $care_tips_completed_count++;
    }
}

$page_title = 'My Health';
$pmh_css_ver = (int) @filemtime(ASSETS_PATH . '/css/patient-my-health.css');
$patient_page_stylesheets = [
    ASSET_BASE . '/assets/css/patient-my-health.css?v=' . $pmh_css_ver,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require_once VIEWS_PATH . '/patient/partials/layout_head.php'; ?>
</head>
<body class="patient-portal">
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

<div class="patient-page pmh-page pmh-page--<?= htmlspecialchars($active_tab) ?>">

  <?php require VIEWS_PATH . '/patient/partials/view_my_health_header.php'; ?>

  <nav class="pmh-tabs pmh-tabs--segment" role="tablist" aria-label="My Health sections">
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=timeline"
       class="pmh-tab <?= $active_tab === 'timeline' ? 'is-active' : '' ?>"
       role="tab" aria-selected="<?= $active_tab === 'timeline' ? 'true' : 'false' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      Care Timeline
    </a>
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=files"
       class="pmh-tab <?= $active_tab === 'files' ? 'is-active' : '' ?>"
       role="tab" aria-selected="<?= $active_tab === 'files' ? 'true' : 'false' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
      Health Files
      <?php if ($counts['all'] > 0): ?>
      <span class="pmh-tab__count"><?= (int) $counts['all'] ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=care-tips"
       class="pmh-tab <?= $active_tab === 'care-tips' ? 'is-active' : '' ?>"
       role="tab" aria-selected="<?= $active_tab === 'care-tips' ? 'true' : 'false' ?>">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Care tips
      <?php if ($care_tips_active_count > 0): ?>
      <span class="pmh-tab__count"><?= (int) $care_tips_active_count ?></span>
      <?php endif; ?>
    </a>
  </nav>

  <div class="pmh-surface" role="tabpanel">
    <?php if ($active_tab === 'timeline'): ?>
      <div class="pmh-surface__head">
        <div>
          <h3 class="pmh-surface__title">Care timeline</h3>
          <p class="pmh-surface__desc">Consultation visits with diagnosis, plans, and prescriptions from your doctors.</p>
        </div>
      </div>
      <?php require VIEWS_PATH . '/patient/partials/view_my_health_timeline.php'; ?>
    <?php elseif ($active_tab === 'files'): ?>
      <div class="pmh-surface__head">
        <div>
          <h3 class="pmh-surface__title">Health files</h3>
          <p class="pmh-surface__desc">Prescriptions, clinical notes, and referrals from your consultations.</p>
        </div>
      </div>
      <?php require VIEWS_PATH . '/patient/partials/view_my_health_files.php'; ?>
    <?php else: ?>
      <div class="pmh-surface__head pmh-surface__head--split">
        <div>
          <h3 class="pmh-surface__title">Self-care guidance</h3>
          <p class="pmh-surface__desc">Provider-approved home care tips from your symptom checks.</p>
        </div>
        <?php if ($care_tips_active_count > 0): ?>
        <button
          type="button"
          class="pmh-btn pmh-btn--primary"
          onclick="if(window.MedConnectPtRemedy&amp;&amp;window.MedConnectPtRemedy.open){window.MedConnectPtRemedy.open();}"
        >
          Open Care Assistant
        </button>
        <?php endif; ?>
      </div>
      <?php require VIEWS_PATH . '/patient/partials/view_my_health_care_tips.php'; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

<script>
document.addEventListener('medconnect:consultation-completed', function () {
  if (document.querySelector('.pmh-feed--timeline')) {
    window.setTimeout(function () { window.location.reload(); }, 1200);
  }
});
function filterHealthFiles(type) {
  document.querySelectorAll('[data-health-filter]').forEach(function (btn) {
    var match = btn.getAttribute('data-health-filter') === type;
    btn.setAttribute('aria-pressed', match ? 'true' : 'false');
  });
  document.querySelectorAll('.pmh-file-card[data-type]').forEach(function (card) {
    card.hidden = !(type === 'all' || card.dataset.type === type);
  });
}
document.querySelectorAll('[data-health-filter]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    filterHealthFiles(btn.getAttribute('data-health-filter'));
  });
});
if (document.getElementById('pmh-files-list')) {
  filterHealthFiles('all');
}
</script>
</body>
</html>
