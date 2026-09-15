<?php
$page_title = 'Referrals';
$bhw_current_file = 'referral/status.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
$bhw_subnav_items = [
    ['file' => 'status.php', 'label' => 'View referrals'],
];
$bhw_subnav_active = 'referral/status.php';
?>
<div class="bhw-referral-status-page">

  <header class="bhw-referral-header">
    <div>
      <h2 class="text-h2">Referrals</h2>
      <p>View doctor-issued referrals for residents in <strong>Brgy. <?= $barangay_label ?></strong>. This list is read-only — you cannot create, edit, approve, or change the doctor’s clinical referral decision.</p>
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
            <th>Reason</th>
            <th>Facility</th>
            <th>Doctor</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="bhwRefBody"></tbody>
      </table>
    </div>
  </div>
</div>
<script>
(function () {
  var tb = document.getElementById('bhwRefBody');

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function short(v, n) {
    var s = String(v == null ? '' : v).trim();
    if (!s) return '—';
    return s.length > n ? s.slice(0, n - 1) + '…' : s;
  }

  function statusLabel(status) {
    var s = String(status || '').toLowerCase();
    if (s === 'pending') return 'Issued / Open';
    if (s === 'accepted') return 'In progress';
    if (s === 'completed') return 'Completed';
    if (s === 'cancelled' || s === 'rejected') return 'Cancelled';
    if (s === 'expired') return 'Expired';
    return status || '—';
  }

  BhwPortal.get('referrals.php', { action: 'list' }).then(function (r) {
    var rows = r.referrals || [];
    if (!rows.length) {
      tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No doctor referrals for your barangay yet.</td></tr>';
      return;
    }
    tb.innerHTML = rows.map(function (x) {
      return '<tr>' +
        '<td>' + esc(x.created_at || '') + '</td>' +
        '<td>' + esc(x.patient_name || '') + '</td>' +
        '<td>' + esc(x.referral_type || '') + '</td>' +
        '<td>' + esc(short(x.reason, 80)) + '</td>' +
        '<td>' + esc(x.facility_display || '—') + '</td>' +
        '<td>' + esc(x.provider_name || '—') + '</td>' +
        '<td>' + esc(statusLabel(x.status)) + '</td>' +
        '</tr>';
    }).join('');
  }).catch(function () {
    tb.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">Could not load referrals.</td></tr>';
  });
})();
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
