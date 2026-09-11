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
<div class="bhw-followup-page">
  <header class="bhw-followup-header">
    <h2 class="text-h2">Follow-Up Monitoring</h2>
    <p>Send in-app reminders for upcoming follow-ups. Each send is logged for audit.</p>
  </header>

  <?php require __DIR__ . '/../partials/bhw_module_subnav.php'; ?>

  <div class="bhw-card bhw-followup-card">
    <div class="table-responsive bhw-followup-table-wrap" id="bhwRemList">
      <p class="bhw-followup-empty">Loading upcoming follow-ups…</p>
    </div>
  </div>
</div>
<script>
(function () {
  var el = document.getElementById('bhwRemList');
  BhwPortal.get('followups.php', { action: 'list', status: 'upcoming' }).then(function (r) {
    var rows = r.followups || [];
    if (!rows.length) {
      el.innerHTML = '<p class="bhw-followup-empty">No upcoming follow-ups.</p>';
      return;
    }
    el.innerHTML = '<table class="table bhw-table bhw-followup-table mb-0"><thead><tr><th>Date</th><th>Patient</th><th>Actions</th></tr></thead><tbody>' +
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
  }).catch(function () {
    el.innerHTML = '<p class="bhw-followup-empty bhw-followup-empty--error">Could not load follow-ups.</p>';
  });
})();
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
