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

$uid = (int) $uid;
$active_tab = ($_GET['tab'] ?? 'timeline') === 'files' ? 'files' : 'timeline';

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
                SELECT consultation_id, medication_name, dosage, frequency
                FROM prescriptions
                WHERE consultation_id IN ($placeholders)
            ");
            $rxStmt->execute(array_values($ids));
            while ($row = $rxStmt->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) ($row['consultation_id'] ?? 0);
                $rx_by_consult[$cid][] = $row;
            }
        } catch (PDOException $e) { /* optional */ }
        try {
            $cnStmt = $pdo->prepare("
                SELECT consultation_id, subjective, objective, assessment, plan, diagnosis, treatment_plan, created_at
                FROM clinical_notes
                WHERE consultation_id IN ($placeholders)
            ");
            $cnStmt->execute(array_values($ids));
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
        FROM prescriptions pr JOIN users u ON u.id = pr.provider_id
        WHERE pr.patient_id = ? ORDER BY pr.created_at DESC
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
        WHERE cn.patient_id = ? ORDER BY cn.created_at DESC
    ");
    $s->execute([$uid]);
    $clinical_notes = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

try {
    $s = $pdo->prepare("
        SELECT CONCAT(dr.referral_type, ' Referral') AS record_name, dr.reason AS frequency,
               COALESCE(dr.destination_facility, '') AS duration, dr.status AS detail,
               DATE(dr.created_at) AS record_date, CONCAT(u.first_name, ' ', u.last_name) AS provider_name
        FROM digital_referrals dr JOIN users u ON u.id = dr.provider_id
        WHERE dr.patient_id = ? ORDER BY dr.created_at DESC
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

<div class="patient-page pmh-page">
  <p class="pmh-lead">
    Your consultation history and provider-issued records.
    For your permanent medical profile (blood type, allergies, medications), see
    <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php">Health Summary</a>.
  </p>

  <div class="pmh-metrics" aria-label="Health overview">
    <div class="pmh-metric">
      <span class="pmh-metric__value"><?= count($history) ?></span>
      <span class="pmh-metric__label">Total visits</span>
    </div>
    <div class="pmh-metric">
      <span class="pmh-metric__value"><?= $completed_visits ?></span>
      <span class="pmh-metric__label">Completed</span>
    </div>
    <div class="pmh-metric">
      <span class="pmh-metric__value"><?= (int) $counts['all'] ?></span>
      <span class="pmh-metric__label">Health files</span>
    </div>
  </div>

  <nav class="pmh-tabs" role="tablist" aria-label="My Health sections">
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
  </nav>

  <div class="pmh-panel" role="tabpanel">
    <?php if ($active_tab === 'files'): ?>
      <?php require VIEWS_PATH . '/patient/partials/view_my_health_files.php'; ?>
    <?php else: ?>
      <?php require VIEWS_PATH . '/patient/partials/view_my_health_timeline.php'; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>

<script>
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
