<?php
$page_title = 'Follow-Up Monitoring';
$bhw_current_file = 'followup/reminders.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$bhw_subnav_items = [
    ['file' => 'track.php', 'label' => 'Track follow-ups'],
    ['file' => 'reminders.php', 'label' => 'Send reminders'],
];
$bhw_subnav_active = 'followup/reminders.php';
?>
<div class="bhw-card">
  <h2 class="text-h2">Follow-Up Monitoring</h2>
  <p class="text-muted">Sends in-app notification to patient and logs audit entry.</p>
  <?php require __DIR__ . '/../partials/bhw_module_subnav.php'; ?>
  <div id="bhwRemList">Loading…</div>
</div>
<script>
BhwPortal.get('followups.php', { action: 'list', status: 'upcoming' }).then(function (r) {
  var el = document.getElementById('bhwRemList');
  var rows = r.followups || [];
  if (!rows.length) { el.innerHTML = '<p>No upcoming follow-ups.</p>'; return; }
  el.innerHTML = '<table class="table bhw-table"><thead><tr><th>Date</th><th>Patient</th><th></th></tr></thead><tbody>' +
    rows.map(function (f) {
      return '<tr><td>' + f.followup_date + '</td><td>' + f.patient_name + '</td><td><button type="button" class="bhw-btn-teal" data-id="' + f.id + '">Send Reminder</button></td></tr>';
    }).join('') + '</tbody></table>';
  el.querySelectorAll('button[data-id]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      BhwPortal.post('followups.php', { action: 'remind', followup_id: btn.dataset.id }).then(function (res) {
        BhwPortal.toast(res.message, res.success);
      });
    });
  });
});
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
