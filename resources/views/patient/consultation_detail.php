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
require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
require_once BASE_PATH . '/app/includes/clinical_tables.php';

clinical_tables_ensure($pdo);
patient_consultation_records_schema_ensure($pdo);

$uid = (int) $uid;
$consultationId = (int) ($_GET['id'] ?? 0);
if ($consultationId <= 0) {
    header('Location: ' . ASSET_BASE . '/views/patient/my_health.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT c.*,
           u.first_name, u.last_name,
           COALESCE(NULLIF(TRIM(c.provider_name), ''), CONCAT(u.first_name, ' ', u.last_name)) AS provider_display
    FROM consultations c
    JOIN users u ON u.id = c.provider_id
    WHERE c.id = ? AND c.patient_id = ?
    LIMIT 1
");
$stmt->execute([$consultationId, $uid]);
$consult = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$consult) {
    http_response_code(403);
    echo 'Consultation not found or access denied.';
    exit;
}

$note = null;
$nStmt = $pdo->prepare('SELECT * FROM clinical_notes WHERE consultation_id = ? AND patient_id = ? LIMIT 1');
$nStmt->execute([$consultationId, $uid]);
$note = $nStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$isFinalized = patient_consultation_is_finalized(
    (string) ($consult['status'] ?? ''),
    $note['signature_data'] ?? ''
);

$prescriptions = [];
try {
    $rx = $pdo->prepare('SELECT * FROM prescriptions WHERE consultation_id = ? AND patient_id = ? ORDER BY created_at ASC');
    $rx->execute([$consultationId, $uid]);
    $prescriptions = $rx->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

$followups = [];
try {
    $fu = $pdo->prepare('SELECT * FROM followups WHERE consultation_id = ? AND patient_id = ? ORDER BY followup_date ASC');
    $fu->execute([$consultationId, $uid]);
    $followups = $fu->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

$referrals = [];
try {
    $rf = $pdo->prepare('SELECT * FROM digital_referrals WHERE consultation_id = ? AND patient_id = ? ORDER BY created_at ASC');
    $rf->execute([$consultationId, $uid]);
    $referrals = $rf->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* optional */ }

$providerName = trim((string) ($consult['provider_display'] ?? ''));
if ($providerName !== '' && stripos($providerName, 'dr.') !== 0) {
    $providerName = 'Dr. ' . $providerName;
}

$chiefComplaint = trim((string) ($consult['consult_type'] ?? ''));
if ($chiefComplaint === '' || strcasecmp($chiefComplaint, 'General consultation') === 0) {
    $chiefComplaint = trim((string) ($consult['diagnosis'] ?? ''));
}

$status = (string) ($consult['status'] ?? '');
$statusLabel = ucwords(str_replace('_', ' ', $status));
$dateLabel = !empty($consult['consult_date']) ? date('F j, Y', strtotime($consult['consult_date'])) : '—';

$clinicalOutcome = $isFinalized
    ? patient_consultation_clinical_outcome($pdo, $consultationId, $uid, false)
    : null;

$page_title = 'Consultation Details';
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

<div class="patient-page pmh-page pmh-page--detail">
  <header class="pmh-detail__head">
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php?tab=timeline" class="pmh-detail__back">← My Health</a>
    <h2 class="pmh-detail__title">Consultation Details</h2>
    <p class="pmh-detail__meta">
      <?= htmlspecialchars($providerName) ?>
      <?php if (!empty($consult['consult_type']) && strcasecmp($consult['consult_type'], 'General consultation') !== 0): ?>
        · <?= htmlspecialchars($consult['consult_type']) ?>
      <?php endif; ?>
    </p>
    <p class="pmh-detail__meta"><?= htmlspecialchars($dateLabel) ?> · <span class="pmh-status pmh-status--<?= $isFinalized ? 'completed' : 'live' ?>"><?= htmlspecialchars($isFinalized ? 'Completed' : $statusLabel) ?></span></p>
  </header>

  <div class="pmh-surface pmh-detail">
    <?php if (!$isFinalized): ?>
      <div class="pmh-detail__pending">
        <p>Your consultation is still being documented by your provider.</p>
        <p class="text-muted">You will receive a notification when your medical record is ready to view.</p>
      </div>
    <?php else: ?>
      <?php if (!empty($clinicalOutcome['final_case_level'])): ?>
      <section class="pmh-detail__section pmh-detail__section--case-level">
        <h3>Final case level</h3>
        <p class="pmh-case-level <?= htmlspecialchars(patient_case_level_chip_class((string) ($clinicalOutcome['final_case_bucket'] ?? ''))) ?>">
          <?= htmlspecialchars((string) $clinicalOutcome['final_case_level']) ?>
        </p>
        <?php if (!empty($clinicalOutcome['final_case_display'])): ?>
        <p class="pmh-detail__case-sub"><?= htmlspecialchars((string) $clinicalOutcome['final_case_display']) ?></p>
        <?php endif; ?>
        <?php if (!empty($clinicalOutcome['ai_case_level']) && $clinicalOutcome['ai_case_level'] !== $clinicalOutcome['final_case_level']): ?>
        <p class="pmh-detail__ai-note">
          <strong>AI triage (reference):</strong> <?= htmlspecialchars((string) $clinicalOutcome['ai_case_level']) ?>
          <?php if (!empty($clinicalOutcome['ai_case_display'])): ?>
            — <?= htmlspecialchars((string) $clinicalOutcome['ai_case_display']) ?>
          <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php if (!empty($clinicalOutcome['recommended_actions'])): ?>
        <ul class="pmh-detail__actions-list">
          <?php foreach ($clinicalOutcome['recommended_actions'] as $action): ?>
          <li><?= htmlspecialchars((string) $action) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if (($clinicalOutcome['final_case_bucket'] ?? '') === 'emergency' && !empty($clinicalOutcome['emergency_warning_signs'])): ?>
        <div class="pmh-detail__emergency">
          <strong>Emergency guidance</strong>
          <ul>
            <?php foreach ($clinicalOutcome['emergency_warning_signs'] as $sign): ?>
            <li><?= htmlspecialchars((string) $sign) ?></li>
            <?php endforeach; ?>
          </ul>
          <p>Go to the nearest emergency department or call local emergency services if symptoms worsen.</p>
        </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="pmh-detail__section">
        <h3>Chief complaint</h3>
        <p><?= htmlspecialchars($chiefComplaint !== '' ? $chiefComplaint : 'Not recorded.') ?></p>
      </section>

      <section class="pmh-detail__section">
        <h3>SOAP notes</h3>
        <dl class="pmh-soap-list">
          <div><dt>Subjective</dt><dd><?= nl2br(htmlspecialchars(trim((string) ($note['subjective'] ?? '')) ?: '—')) ?></dd></div>
          <div><dt>Objective</dt><dd><?= nl2br(htmlspecialchars(trim((string) ($note['objective'] ?? '')) ?: '—')) ?></dd></div>
          <div><dt>Assessment</dt><dd><?= nl2br(htmlspecialchars(trim((string) ($note['assessment'] ?? '')) ?: '—')) ?></dd></div>
          <div><dt>Plan</dt><dd><?= nl2br(htmlspecialchars(trim((string) ($note['plan'] ?? '')) ?: '—')) ?></dd></div>
        </dl>
        <?php if (trim((string) ($note['diagnosis'] ?? '')) !== ''): ?>
        <p class="pmh-detail__diag"><strong>Diagnosis:</strong> <?= htmlspecialchars($note['diagnosis']) ?></p>
        <?php endif; ?>
      </section>

      <?php if (!empty($prescriptions)): ?>
      <section class="pmh-detail__section">
        <h3>Prescription</h3>
        <ul class="pmh-rx-list">
          <?php foreach ($prescriptions as $rx): ?>
          <li>
            <strong><?= htmlspecialchars($rx['medication_name'] ?? '') ?></strong>
            <span><?= htmlspecialchars(trim(($rx['dosage'] ?? '') . ' · ' . ($rx['frequency'] ?? '') . ' · ' . ($rx['duration'] ?? ''))) ?></span>
            <?php if (!empty($rx['notes'])): ?><p><?= nl2br(htmlspecialchars($rx['notes'])) ?></p><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if (trim((string) ($note['prescription'] ?? '')) !== '' && empty($prescriptions[0]['notes'])): ?>
        <p><?= nl2br(htmlspecialchars($note['prescription'])) ?></p>
        <?php endif; ?>
      </section>
      <?php elseif (trim((string) ($note['prescription'] ?? '')) !== ''): ?>
      <section class="pmh-detail__section">
        <h3>Prescription</h3>
        <p><?= nl2br(htmlspecialchars($note['prescription'])) ?></p>
      </section>
      <?php endif; ?>

      <?php if (!empty($followups)): ?>
      <section class="pmh-detail__section">
        <h3>Follow-up</h3>
        <ul class="pmh-rx-list">
          <?php foreach ($followups as $fu): ?>
          <li>
            <strong><?= !empty($fu['followup_date']) ? date('M j, Y', strtotime($fu['followup_date'])) : 'Follow-up' ?></strong>
            <?php if (!empty($fu['message'])): ?><p><?= nl2br(htmlspecialchars($fu['message'])) ?></p><?php endif; ?>
            <?php if (!empty($fu['notes'])): ?><p><?= nl2br(htmlspecialchars($fu['notes'])) ?></p><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <?php if (!empty($referrals)): ?>
      <section class="pmh-detail__section">
        <h3>Referrals</h3>
        <ul class="pmh-rx-list">
          <?php foreach ($referrals as $ref): ?>
          <li>
            <strong><?= htmlspecialchars($ref['referral_type'] ?? 'Referral') ?></strong>
            <?php if (!empty($ref['reason'])): ?><p><?= nl2br(htmlspecialchars($ref['reason'])) ?></p><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <?php if (trim((string) ($note['treatment_plan'] ?? '')) !== ''): ?>
      <section class="pmh-detail__section">
        <h3>Care plan</h3>
        <p><?= nl2br(htmlspecialchars($note['treatment_plan'])) ?></p>
      </section>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once VIEWS_PATH . '/patient/partials/layout_shell_close.php'; ?>
</body>
</html>
