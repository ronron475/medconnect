<?php
$page_title = 'Track Follow-Ups';
$bhw_current_file = 'followup/track.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$today = date('Y-m-d');
?>
<div class="bhw-card">
  <h2 class="text-h2">Track Patient Follow-Ups</h2>
  <p class="text-muted">Monitor scheduled follow-ups and log home visits after seeing the patient in person.</p>
  <select id="bhwFuFilter" class="form-select mb-3" style="max-width:220px;" aria-label="Filter follow-ups">
    <option value="">All</option>
    <option value="upcoming">Upcoming</option>
    <option value="missed">Missed</option>
    <option value="completed">Completed</option>
  </select>
  <div class="table-responsive">
    <table class="table bhw-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Patient</th>
          <th>Status</th>
          <th>Home visits</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="bhwFuBody"><tr><td colspan="5">Loading…</td></tr></tbody>
    </table>
  </div>
</div>

<div id="bhwVisitModal" class="bhw-feedback-overlay" style="display:none;" aria-hidden="true">
  <div class="bhw-card" style="max-width:480px;margin:10vh auto;padding:24px;" role="dialog" aria-labelledby="bhwVisitTitle">
    <h3 id="bhwVisitTitle" class="text-h3">Log Home Visit</h3>
    <p class="text-muted small" id="bhwVisitPatientLabel">—</p>
    <form id="bhwVisitForm">
      <input type="hidden" name="followup_id" id="bhwVisitFollowupId">
      <input type="hidden" name="patient_id" id="bhwVisitPatientId">
      <div class="mb-2">
        <label class="form-label">Visit date</label>
        <input type="date" name="visit_date" id="bhwVisitDate" class="form-control" value="<?= htmlspecialchars($today) ?>" required>
      </div>
      <div class="mb-2">
        <label class="form-label">Visit type</label>
        <select name="visit_type" class="form-select">
          <option value="follow_up">Follow-up</option>
          <option value="monitoring">Monitoring</option>
          <option value="emergency_check">Emergency check</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="mb-2">
        <label class="form-label">Patient status</label>
        <select name="patient_status" class="form-select">
          <option value="improving">Improving</option>
          <option value="stable" selected>Stable</option>
          <option value="worsening">Worsening</option>
          <option value="referred">Referred</option>
          <option value="unknown">Unknown</option>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="Observations from home visit…"></textarea>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="bhw-btn-teal">Save Visit Log</button>
        <button type="button" class="bhw-btn-outline" id="bhwVisitCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('bhwVisitModal');

  function openVisitModal(f) {
    document.getElementById('bhwVisitFollowupId').value = f.id;
    document.getElementById('bhwVisitPatientId').value = f.patient_id;
    document.getElementById('bhwVisitPatientLabel').textContent = 'Patient: ' + f.patient_name;
    modal.style.display = 'block';
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeVisitModal() {
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
  }

  document.getElementById('bhwVisitCancel').addEventListener('click', closeVisitModal);

  function loadFu() {
    BhwPortal.get('followups.php', { action: 'list', status: document.getElementById('bhwFuFilter').value }).then(function (r) {
      var tb = document.getElementById('bhwFuBody');
      var rows = r.followups || [];
      if (!rows.length) {
        tb.innerHTML = '<tr><td colspan="5">No follow-ups.</td></tr>';
        return;
      }
      tb.innerHTML = rows.map(function (f) {
        var visits = (parseInt(f.home_visit_count, 10) || 0) + (f.last_home_visit ? ' (last: ' + f.last_home_visit + ')' : '');
        return '<tr>' +
          '<td>' + f.followup_date + '</td>' +
          '<td>' + f.patient_name + '</td>' +
          '<td>' + f.status + '</td>' +
          '<td>' + visits + '</td>' +
          '<td><button type="button" class="bhw-btn-teal bhw-log-visit" data-id="' + f.id + '">Log home visit</button></td>' +
          '</tr>';
      }).join('');
      tb.querySelectorAll('.bhw-log-visit').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = parseInt(btn.dataset.id, 10);
          var row = rows.find(function (x) { return parseInt(x.id, 10) === id; });
          if (row) openVisitModal(row);
        });
      });
    });
  }

  document.getElementById('bhwFuFilter').addEventListener('change', loadFu);

  document.getElementById('bhwVisitForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(e.target);
    fd.append('action', 'log_visit');
    BhwPortal.post('followups.php', fd).then(function (res) {
      BhwPortal.toast(res.message, res.success);
      if (res.success) {
        closeVisitModal();
        loadFu();
      }
    });
  });

  loadFu();
})();
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
