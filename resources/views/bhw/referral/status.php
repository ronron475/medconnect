<?php
$page_title = 'Referral Status';
$bhw_current_file = 'referral/status.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
?>
<div class="bhw-card">
  <h2 class="text-h2">Referral Status — Brgy. <?= htmlspecialchars($bhw_barangay_name) ?></h2>
  <div class="table-responsive"><table class="table bhw-table"><thead><tr><th>Date</th><th>Patient</th><th>Type</th><th>Facility</th><th>Status</th></tr></thead>
  <tbody id="bhwRefBody"><tr><td colspan="5">Loading…</td></tr></tbody></table></div>
</div>
<script>
BhwPortal.get('referrals.php', { action: 'list' }).then(function (r) {
  var tb = document.getElementById('bhwRefBody');
  var rows = r.referrals || [];
  if (!rows.length) { tb.innerHTML = '<tr><td colspan="5">No referrals yet.</td></tr>'; return; }
  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
  tb.innerHTML = rows.map(function (x) {
    return '<tr><td>' + esc(x.created_at || '') + '</td><td>' + esc(x.patient_name) + '</td><td>' + esc(x.referral_type) + '</td><td>' + esc(x.facility_display || '—') + '</td><td>' + esc(x.status) + '</td></tr>';
  }).join('');
});
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
