<?php
$active_page = 'prescriptions';
$page_title  = 'e-Prescriptions';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
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

$patient_options = $patients ?? [];
?>

<div class="greeting-banner" style="margin-bottom:20px;">
  <div><h2 class="text-h2">e-Prescriptions</h2><p class="text-muted text-sm">Create, print, and review prescription history.</p></div>
  <button type="button" class="mc-btn mc-btn--primary" id="rxOpenForm">+ New Prescription</button>
</div>

<div id="rxFormCard" class="mc-card" style="margin-bottom:16px;display:none;">
  <h3 class="text-h3 mb-md">Create Prescription</h3>
  <form id="rxForm" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
    <div style="grid-column:span 2;">
      <label class="text-xs text-muted">Patient</label>
      <select name="patient_id" required class="mc-btn mc-btn--outline" style="width:100%;background:#fff;display:block;">
        <option value="">Select patient…</option>
        <?php foreach ($patient_options as $p): ?>
        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name'] ?? '') ?></option>
        <?php endforeach; ?>
      </select>
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
        <td><?= htmlspecialchars($rx['first_name'].' '.$rx['last_name']) ?></td>
        <td><strong><?= htmlspecialchars($rx['medication_name']) ?></strong></td>
        <td><?= htmlspecialchars($rx['dosage']) ?></td>
        <td><?= htmlspecialchars($rx['frequency']) ?></td>
        <td><?= htmlspecialchars($rx['duration']) ?></td>
        <td><button type="button" class="mc-btn mc-btn--outline mc-btn--sm" onclick="window.print()">Print</button></td>
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
  var fd = new FormData(e.target);
  fd.append('csrf_token', document.body.dataset.csrf || '');
  fetch('<?= ASSET_BASE ?>/app/api/provider/create_prescription.php', { method: 'POST', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (j) { alert(j.message || 'Done'); if (j.success) location.reload(); });
});
</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
