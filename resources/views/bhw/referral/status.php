<?php
$page_title = 'Referrals';
$bhw_current_file = 'referral/status.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
$bhw_subnav_items = [
    ['file' => 'status.php', 'label' => 'Referral status'],
    ['file' => 'create.php', 'label' => 'New referral'],
];
$bhw_subnav_active = 'referral/status.php';
?>
<div class="bhw-referral-status-page">

  <header class="bhw-referral-header">
    <div>
      <h2 class="text-h2">Referrals</h2>
      <p>Track hospital and facility referrals for residents in <strong>Brgy. <?= $barangay_label ?></strong>.</p>
    </div>
  </header>

  <?php require __DIR__ . '/../partials/bhw_module_subnav.php'; ?>

  <div class="bhw-card bhw-referral-table-card">
    <div class="table-responsive">
      <table class="table bhw-table mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Patient</th>
            <th>Type</th>
            <th>Facility</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="bhwRefBody">
          <tr><td colspan="5" class="text-center text-muted py-4">Loading referrals…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
BhwPortal.get('referrals.php', { action: 'list' }).then(function (r) {
  var tb = document.getElementById('bhwRefBody');
  var rows = r.referrals || [];
  if (!rows.length) {
    tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No referrals yet. <a href="create.php">Create one</a>.</td></tr>';
    return;
  }
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
}).catch(function () {
  document.getElementById('bhwRefBody').innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Could not load referrals.</td></tr>';
});
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
