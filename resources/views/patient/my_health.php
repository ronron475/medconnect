<?php
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
require_once BASE_PATH . '/app/includes/clinical_note_signature.php';
require_once BASE_PATH . '/app/includes/community_bhw_activity.php';

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
$outcomes_by_consult = [];
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
                       cn.diagnosis, cn.treatment_plan, cn.signature_name, cn.signed_at, cn.finalized_at, cn.created_at
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
        foreach ($history as $hRow) {
            $hid = (int) ($hRow['id'] ?? 0);
            if ($hid <= 0 || strtolower((string) ($hRow['status'] ?? '')) !== 'completed') {
                continue;
            }
            if (empty($notes_by_consult[$hid])) {
                continue;
            }
            $outcome = patient_consultation_clinical_outcome($pdo, $hid, $uid, false);
            if ($outcome) {
                $outcomes_by_consult[$hid] = $outcome;
            }
        }
    }
}

$prescriptions = [];
$clinical_notes = [];
$referrals = [];
try {
    $s = $pdo->prepare("
        SELECT CONCAT(pr.medication_name, ' ', pr.dosage) AS record_name,
               pr.medication_name, pr.dosage, pr.frequency, pr.duration,
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
        SELECT cn.consultation_id,
               CONCAT('Consultation #', cn.consultation_id, ' SOAP Note') AS record_name,
               cn.assessment AS frequency,
               cn.plan AS duration,
               COALESCE(NULLIF(cn.treatment_plan, ''), NULLIF(cn.subjective, ''), '') AS detail,
               cn.subjective, cn.objective, cn.assessment, cn.plan, cn.diagnosis, cn.treatment_plan,
               cn.signature_name, cn.signed_at, cn.finalized_at,
               DATE(COALESCE(cn.finalized_at, cn.created_at)) AS record_date,
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
    $historyById = [];
    foreach ($history as $hRow) {
        $hid = (int) ($hRow['id'] ?? 0);
        if ($hid > 0) {
            $historyById[$hid] = $hRow;
        }
    }
    foreach ($clinical_notes as &$cnRow) {
        $cid = (int) ($cnRow['consultation_id'] ?? 0);
        $cnRow['chief_complaint'] = $cid > 0
            ? patient_session_chief_complaint($pdo, $uid, $historyById[$cid] ?? ['id' => $cid])
            : '';
    }
    unset($cnRow);
} catch (PDOException $e) { /* optional */ }

try {
    $destCol = $pdo->query("SHOW COLUMNS FROM digital_referrals LIKE 'facility_name'")->fetch()
        ? 'facility_name'
        : 'destination_facility';
    $hasNotes = (bool) $pdo->query("SHOW COLUMNS FROM digital_referrals LIKE 'provider_notes'")->fetch();
    $notesExpr = $hasNotes ? 'COALESCE(dr.provider_notes, \'\')' : '\'\'';
    $s = $pdo->prepare("
        SELECT CONCAT(dr.referral_type, ' Referral') AS record_name,
               dr.referral_type AS referral_type,
               dr.reason AS referral_reason,
               COALESCE(dr.{$destCol}, '') AS referral_facility,
               {$notesExpr} AS referral_notes,
               DATE(dr.created_at) AS record_date,
               CONCAT(u.first_name, ' ', u.last_name) AS provider_name,
               dr.reason AS frequency,
               COALESCE(dr.{$destCol}, '') AS duration,
               {$notesExpr} AS detail
        FROM digital_referrals dr
        LEFT JOIN consultations c ON c.id = dr.consultation_id
        JOIN users u ON u.id = dr.provider_id
        WHERE dr.patient_id = ?
        ORDER BY dr.created_at DESC
    ");
    $s->execute([$uid]);
    $referrals = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

$all_records = [];
foreach ($prescriptions as $r) { $r['record_type'] = 'Prescription'; $all_records[] = $r; }
foreach ($clinical_notes as $r) { $r['record_type'] = 'Health File'; $all_records[] = $r; }
foreach ($referrals as $r) { $r['record_type'] = 'Referral'; $all_records[] = $r; }
usort($all_records, fn($a, $b) => strcmp($b['record_date'] ?? '', $a['record_date'] ?? ''));

$counts = [
    'Prescription'  => count($prescriptions),
    'Health File'   => count($clinical_notes),
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

$bhw_activity = community_bhw_activity_load($pdo, $uid);
$bhw_activity_variant = 'patient';

$timeline_assessments = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'triage_results'")->rowCount()) {
        $ta = $pdo->prepare("
            SELECT id, chief_complaint, assessed_at, triage_classification, level, urgency_label,
                   triage_level, outcome, recommendation_status
            FROM triage_results
            WHERE patient_id = ?
            ORDER BY assessed_at DESC, id DESC
            LIMIT 20
        ");
        $ta->execute([$uid]);
        $timeline_assessments = $ta->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $timeline_assessments = [];
}

$care_timeline = patient_care_timeline_build($history, $bhw_activity, $timeline_assessments);

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
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      corePlugins: { preflight: false },
    };
  </script>
</head>
<body class="patient-portal">
<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_open.php'; ?>

<div class="patient-page pmh-page pmh-page--<?= htmlspecialchars($active_tab) ?>">

  <?php require VIEWS_PATH . '/patient/partials/view_my_health_header.php'; ?>

  <section class="pmh-panel" aria-label="My Health records">
    <nav class="pmh-tabs pmh-tabs--segment" role="tablist" aria-label="My Health sections">
      <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=timeline"
         class="pmh-tab <?= $active_tab === 'timeline' ? 'is-active' : '' ?>"
         role="tab" aria-selected="<?= $active_tab === 'timeline' ? 'true' : 'false' ?>">
        Care Timeline
        <?php if (!empty($care_timeline)): ?>
        <span class="pmh-tab__count"><?= count($care_timeline) ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=files"
         class="pmh-tab <?= $active_tab === 'files' ? 'is-active' : '' ?>"
         role="tab" aria-selected="<?= $active_tab === 'files' ? 'true' : 'false' ?>">
        Health Files
        <?php if ($counts['all'] > 0): ?>
        <span class="pmh-tab__count"><?= (int) $counts['all'] ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=care-tips"
         class="pmh-tab <?= $active_tab === 'care-tips' ? 'is-active' : '' ?>"
         role="tab" aria-selected="<?= $active_tab === 'care-tips' ? 'true' : 'false' ?>">
        Care tips
        <?php if ($care_tips_active_count > 0): ?>
        <span class="pmh-tab__count"><?= (int) $care_tips_active_count ?></span>
        <?php endif; ?>
      </a>
    </nav>

    <div class="pmh-surface<?= in_array($active_tab, ['timeline', 'files'], true) ? ' w-full max-w-none' : '' ?>" role="tabpanel">
      <?php if ($active_tab === 'timeline'): ?>
        <header class="pmh-surface__head pmh-surface__head--compact pmh-surface__head--timeline">
          <h3 class="pmh-surface__title">Care timeline</h3>
          <p class="pmh-surface__desc">Consultations, assessments, and recorded health activity.</p>
        </header>
        <?php require VIEWS_PATH . '/patient/partials/view_my_health_timeline.php'; ?>
      <?php elseif ($active_tab === 'files'): ?>
        <header class="pmh-surface__head pmh-surface__head--compact pmh-surface__head--files">
          <h3 class="pmh-surface__title">Health files</h3>
          <p class="pmh-surface__desc">Finalized consultation records signed by your provider.</p>
        </header>
        <?php require VIEWS_PATH . '/patient/partials/view_my_health_files.php'; ?>
      <?php else: ?>
        <div class="pmh-surface__head pmh-surface__head--split pmh-surface__head--compact">
          <div>
            <h3 class="pmh-surface__title">Self-care guidance</h3>
            <p class="pmh-surface__desc">Provider-approved tips from your triage assessments.</p>
          </div>
          <?php if ($care_tips_active_count > 0): ?>
          <button
            type="button"
            class="pmh-btn pmh-btn--primary pmh-btn--sm"
            onclick="if(window.MedConnectPtRemedy&amp;&amp;window.MedConnectPtRemedy.open){window.MedConnectPtRemedy.open();}"
          >
            Open Care Assistant
          </button>
          <?php endif; ?>
        </div>
        <?php require VIEWS_PATH . '/patient/partials/view_my_health_care_tips.php'; ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

<script>
document.addEventListener('medconnect:consultation-completed', function () {
  if (document.querySelector('.pmh-feed--timeline') || document.getElementById('pmh-files-list')) {
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
  var hashTarget = window.location.hash ? document.getElementById(window.location.hash.slice(1)) : null;
  if (hashTarget) {
    window.setTimeout(function () {
      hashTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
      hashTarget.classList.add('pmh-file-card--highlight');
    }, 150);
  }
}
</script>
</body>
</html>
