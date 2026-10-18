<?php
$active_page = 'prescriptions';
$page_title  = 'e-Prescriptions';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require_once BASE_PATH . '/app/includes/clinical_tables.php';
clinical_tables_ensure($pdo);
require __DIR__.'/partials/layout_open.php';

$provider_id = (int)$_SESSION['user_id'];
$search = trim($_GET['q'] ?? '');

$history_sql = "
    SELECT p.*, u.first_name, u.last_name
    FROM prescriptions p
    JOIN users u ON u.id = p.patient_id
    WHERE p.provider_id = ?
";
$params = [$provider_id];
if ($search !== '') {
    $history_sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR p.medication_name LIKE ?)";
    $s = "%$search%"; array_push($params, $s, $s, $s);
}
$history_sql .= " ORDER BY p.created_at DESC LIMIT 100";

$prescriptions = [];
try {
    $stmt = $pdo->prepare($history_sql);
    $stmt->execute($params);
    $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$consult_options = [];
try {
    $consultStmt = $pdo->prepare("
        SELECT c.id, c.patient_id, c.consult_date, c.consult_time, c.status,
               u.first_name, u.last_name
        FROM consultations c
        JOIN users u ON u.id = c.patient_id
        LEFT JOIN clinical_notes cn ON cn.consultation_id = c.id
        WHERE c.provider_id = ?
          AND LOWER(COALESCE(c.status, '')) NOT IN ('cancelled', 'canceled', 'completed')
          AND (
            cn.consultation_id IS NULL
            OR (
              (cn.finalized_at IS NULL OR TRIM(cn.finalized_at) = '' OR cn.finalized_at = '0000-00-00 00:00:00')
              AND (cn.signature_data IS NULL OR TRIM(cn.signature_data) = '')
            )
          )
        ORDER BY c.id DESC
        LIMIT 100
    ");
    $consultStmt->execute([$provider_id]);
    $consult_options = $consultStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $consult_options = [];
}
?>

<div class="greeting-banner provider-page-intro">
  <p class="text-muted text-sm provider-page-intro__desc">Create, print, and review prescription history.</p>
  <button type="button" class="mc-btn mc-btn--primary" id="rxOpenForm">+ New Prescription</button>
</div>

<div id="rxFormCard" class="mc-card" style="margin-bottom:16px;display:none;">
  <h3 class="text-h3 mb-md">Create Prescription</h3>
  <form id="rxForm" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div style="grid-column:span 2;">
      <label class="text-xs text-muted">Consultation</label>
      <select name="consultation_id" id="rxConsultationId" required class="mc-btn mc-btn--outline" style="width:100%;background:#fff;display:block;" <?= empty($consult_options) ? 'disabled' : '' ?>>
        <option value="">Select consultation…</option>
        <?php foreach ($consult_options as $consult): ?>
        <?php
          $consultPatient = trim((string) (($consult['first_name'] ?? '') . ' ' . ($consult['last_name'] ?? '')));
          $consultWhen = trim((string) ($consult['consult_date'] ?? ''));
          if (!empty($consult['consult_time'])) {
              $consultWhen .= ' ' . substr((string) $consult['consult_time'], 0, 5);
          }
          $consultStatus = str_replace('_', ' ', (string) ($consult['status'] ?? ''));
          $consultLabel = '#' . (int) $consult['id'];
          if ($consultWhen !== '') {
              $consultLabel .= ' · ' . $consultWhen;
          }
          $consultLabel .= ' · ' . ($consultPatient !== '' ? $consultPatient : 'Patient');
          if ($consultStatus !== '') {
              $consultLabel .= ' · ' . $consultStatus;
          }
        ?>
        <option value="<?= (int) $consult['id'] ?>" data-patient="<?= (int) $consult['patient_id'] ?>"><?= htmlspecialchars($consultLabel) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="patient_id" id="rxPatientId" value="">
      <?php if (empty($consult_options)): ?>
      <p class="text-xs text-muted">No eligible consultation is available. A prescription can only be issued for an existing visit.</p>
      <?php endif; ?>
    </div>
    <div><label class="text-xs text-muted">Medication</label><input name="medication_name" required class="mc-btn mc-btn--outline" style="width:100%;background:#fff;"></div>
    <div><label class="text-xs text-muted">Dosage</label><input name="dosage" required class="mc-btn mc-btn--outline" style="width:100%;background:#fff;"></div>
    <div><label class="text-xs text-muted">Frequency</label><input name="frequency" required class="mc-btn mc-btn--outline" style="width:100%;background:#fff;"></div>
    <div><label class="text-xs text-muted">Duration</label><input name="duration" required class="mc-btn mc-btn--outline" style="width:100%;background:#fff;"></div>
    <div style="grid-column:span 2;"><label class="text-xs text-muted">Notes</label><textarea name="notes" rows="2" class="mc-btn mc-btn--outline" style="width:100%;background:#fff;"></textarea></div>
    <div style="grid-column:span 2;"><button type="submit" class="mc-btn mc-btn--primary">Save Prescription</button></div>
  </form>
</div>

<div class="mc-card" style="margin-bottom:16px;padding:16px;">
  <form method="get"><input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search patient or medication…" class="mc-btn mc-btn--outline" style="width:100%;max-width:360px;background:#fff;"></form>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table" id="rxTable">
    <thead><tr><th>Date</th><th>Patient</th><th>Medication</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($prescriptions)): ?>
      <tr><td colspan="7"><div class="mc-table-empty"><p>No prescriptions yet.</p></div></td></tr>
      <?php else: foreach ($prescriptions as $rx): ?>
      <tr>
        <td><?= date('M j, Y', strtotime($rx['created_at'])) ?></td>
        <td><?= htmlspecialchars(trim(($rx['first_name'] ?? '') . ' ' . ($rx['last_name'] ?? '')) ?: 'Patient') ?></td>
        <td><strong><?= htmlspecialchars($rx['medication_name']) ?></strong></td>
        <td><?= htmlspecialchars($rx['dosage']) ?></td>
        <td><?= htmlspecialchars($rx['frequency']) ?></td>
        <td><?= htmlspecialchars($rx['duration']) ?></td>
        <td>
          <button type="button" class="mc-btn mc-btn--outline mc-btn--sm" onclick="window.print()">Print</button>
          <?php if ((string) ($rx['status'] ?? '') === 'active'): ?>
          <button type="button" class="mc-btn mc-btn--outline mc-btn--sm" data-rx-revoke="<?= (int) $rx['id'] ?>">Revoke</button>
          <?php elseif ((string) ($rx['status'] ?? '') === 'revoked'): ?>
          <span class="text-xs text-muted">Revoked</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
document.getElementById('rxOpenForm').addEventListener('click', function () {
  document.getElementById('rxFormCard').style.display = 'block';
});
document.getElementById('rxForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var select = document.getElementById('rxConsultationId');
  var patient = document.getElementById('rxPatientId');
  var option = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
  if (patient) patient.value = option ? (option.getAttribute('data-patient') || '') : '';
  var fd = new FormData(e.target);
  fd.append('csrf_token', document.body.dataset.csrf || '');
  fetch('<?= ASSET_BASE ?>/app/api/provider/create_prescription.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) { alert(j.message || 'Done'); if (j.success) location.reload(); });
});
document.querySelectorAll('[data-rx-revoke]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    if (!window.confirm('Revoke this prescription? It stays in the patient record, and verification will show it as revoked.')) {
      return;
    }
    var fd = new FormData();
    fd.append('csrf_token', document.body.dataset.csrf || '');
    fd.append('prescription_id', btn.getAttribute('data-rx-revoke') || '');
    fetch('<?= ASSET_BASE ?>/app/api/provider/revoke_prescription.php', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) { alert(j.message || 'Done'); if (j.success) location.reload(); });
  });
});
</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
