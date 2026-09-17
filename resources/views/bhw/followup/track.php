<?php
$page_title = 'Follow-Up Monitoring';
$bhw_current_file = 'followup/track.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$today = date('Y-m-d');
$bhw_subnav_items = [
    ['file' => 'track.php', 'label' => 'Track follow-ups'],
    ['file' => 'reminders.php', 'label' => 'Send reminders'],
];
$bhw_subnav_active = 'followup/track.php';
?>
<div class="bhw-followup-page">
  <header class="bhw-followup-header bhw-page-intro">
    <p class="bhw-page-intro__desc">Monitor doctor-scheduled follow-ups for residents in your barangay and log community home visits.</p>
  </header>

  <?php require __DIR__ . '/../partials/bhw_module_subnav.php'; ?>

  <div class="bhw-card bhw-followup-card">
    <div class="bhw-followup-toolbar">
      <label class="bhw-followup-filter-label" for="bhwFuFilter">Status</label>
      <select id="bhwFuFilter" class="form-select bhw-followup-filter" aria-label="Filter follow-ups">
        <option value="">All</option>
        <option value="upcoming">Upcoming</option>
        <option value="missed">Missed</option>
        <option value="completed">Completed</option>
        <option value="unscheduled">Unscheduled</option>
      </select>
    </div>
    <div class="table-responsive bhw-followup-table-wrap">
      <table class="table bhw-table bhw-followup-table mb-0">
        <thead>
          <tr>
            <th>Date</th>
            <th>Patient</th>
            <th>Status</th>
            <th>Home visits</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="bhwFuBody">
          <tr><td colspan="5" class="bhw-followup-empty">Loading follow-ups…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="bhwFuDetailModal" class="bhw-feedback-overlay" style="display:none;" aria-hidden="true">
  <div class="bhw-card bhw-followup-detail-card" role="dialog" aria-labelledby="bhwFuDetailTitle">
    <div class="bhw-followup-detail-head">
      <h3 id="bhwFuDetailTitle" class="text-h3">Follow-up details</h3>
      <button type="button" class="bhw-btn-outline bhw-followup-detail-close" id="bhwFuDetailClose" aria-label="Close">Close</button>
    </div>
    <div id="bhwFuDetailBody" class="bhw-followup-detail-body">
      <p class="text-muted small">Loading…</p>
    </div>
    <div class="bhw-followup-detail-actions">
      <button type="button" class="bhw-btn-teal" id="bhwFuDetailLogVisit" style="display:none;">Log home visit</button>
    </div>
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
      <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="bhw-btn-teal">Save Visit Log</button>
        <button type="button" class="bhw-btn-outline" id="bhwVisitCancel">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  var visitModal = document.getElementById('bhwVisitModal');
  var detailModal = document.getElementById('bhwFuDetailModal');
  var detailBody = document.getElementById('bhwFuDetailBody');
  var detailLogBtn = document.getElementById('bhwFuDetailLogVisit');
  var rowsCache = [];
  var activeDetail = null;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function statusBadge(f) {
    var key = f.display_status_key || 'unknown';
    var label = f.display_status || f.status || 'Unknown';
    return '<span class="bhw-fu-status bhw-fu-status--' + esc(key) + '">' + esc(label) + '</span>';
  }

  function openVisitModal(f) {
    document.getElementById('bhwVisitFollowupId').value = f.id;
    document.getElementById('bhwVisitPatientId').value = f.patient_id;
    document.getElementById('bhwVisitPatientLabel').textContent =
      'Patient: ' + (f.patient_name || '—') + ' · Follow-up #' + f.id;
    visitModal.style.display = 'block';
    visitModal.setAttribute('aria-hidden', 'false');
  }

  function closeVisitModal() {
    visitModal.style.display = 'none';
    visitModal.setAttribute('aria-hidden', 'true');
  }

  function closeDetailModal() {
    detailModal.style.display = 'none';
    detailModal.setAttribute('aria-hidden', 'true');
    activeDetail = null;
    detailLogBtn.style.display = 'none';
  }

  function renderDetail(payload) {
    var f = payload.followup || {};
    var visits = payload.visits || [];
    activeDetail = f;
    var doctorNote = (f.message || f.notes || '').trim();
    var visitsHtml;
    if (!visits.length) {
      visitsHtml = '<p class="bhw-followup-empty" style="padding:12px 0;">No home visits logged yet.</p>';
    } else {
      visitsHtml = '<ul class="bhw-fu-visit-list">' + visits.map(function (v) {
        return '<li><strong>' + esc(v.visit_date) + '</strong> · ' + esc(v.visit_type || 'follow_up') +
          ' · ' + esc(v.patient_status || '—') +
          (v.notes ? '<br><span class="text-muted">' + esc(v.notes) + '</span>' : '') +
          (v.bhw_name ? '<br><span class="text-muted">By ' + esc(v.bhw_name) + '</span>' : '') +
          '</li>';
      }).join('') + '</ul>';
    }

    detailBody.innerHTML =
      '<dl class="bhw-fu-detail-grid">' +
        '<div><dt>Patient</dt><dd>' + esc(f.patient_name || '—') + '</dd></div>' +
        '<div><dt>Follow-up date</dt><dd>' + esc(f.followup_datetime_label || f.followup_date || 'Date TBD') + '</dd></div>' +
        '<div><dt>Status</dt><dd>' + statusBadge(f) + '</dd></div>' +
        '<div><dt>Doctor</dt><dd>' + esc((f.provider_name || '').trim() || '—') + '</dd></div>' +
        '<div><dt>Reference</dt><dd>#' + esc(f.id) + '</dd></div>' +
        '<div><dt>Home visits</dt><dd>' + esc(f.home_visit_label || '—') + '</dd></div>' +
      '</dl>' +
      '<div class="bhw-fu-detail-block">' +
        '<h4>Doctor follow-up note (read-only)</h4>' +
        '<p>' + esc(doctorNote !== '' ? doctorNote : 'No doctor note on this follow-up.') + '</p>' +
      '</div>' +
      '<div class="bhw-fu-detail-block">' +
        '<h4>Community home visits</h4>' +
        visitsHtml +
      '</div>';

    detailLogBtn.style.display = 'inline-flex';
    detailModal.style.display = 'block';
    detailModal.setAttribute('aria-hidden', 'false');
  }

  function openDetail(id) {
    detailBody.innerHTML = '<p class="text-muted small">Loading…</p>';
    detailModal.style.display = 'block';
    detailModal.setAttribute('aria-hidden', 'false');
    BhwPortal.get('followups.php', { action: 'get', followup_id: id }).then(function (r) {
      if (!r.success) {
        detailBody.innerHTML = '<p class="bhw-followup-empty bhw-followup-empty--error">' +
          esc(r.message || 'Could not load follow-up.') + '</p>';
        detailLogBtn.style.display = 'none';
        return;
      }
      renderDetail(r);
    }).catch(function () {
      detailBody.innerHTML = '<p class="bhw-followup-empty bhw-followup-empty--error">Could not load follow-up.</p>';
      detailLogBtn.style.display = 'none';
    });
  }

  function loadFu() {
    var tb = document.getElementById('bhwFuBody');
    tb.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty">Loading follow-ups…</td></tr>';
    BhwPortal.get('followups.php', { action: 'list', status: document.getElementById('bhwFuFilter').value }).then(function (r) {
      if (!r.success) {
        tb.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty bhw-followup-empty--error">' +
          esc(r.message || 'Could not load follow-ups.') + '</td></tr>';
        return;
      }
      rowsCache = r.followups || [];
      if (!rowsCache.length) {
        tb.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty">No doctor follow-ups found for your barangay with this filter.</td></tr>';
      } else {
        tb.innerHTML = rowsCache.map(function (f) {
          return '<tr>' +
            '<td data-label="Date">' + esc(f.followup_datetime_label || f.followup_date || 'Date TBD') + '</td>' +
            '<td data-label="Patient"><span class="bhw-fu-patient-name">' + esc(f.patient_name || '—') + '</span>' +
              (f.provider_name ? '<span class="bhw-fu-patient-sub">Dr. ' + esc(String(f.provider_name).trim()) + '</span>' : '') +
            '</td>' +
            '<td data-label="Status">' + statusBadge(f) + '</td>' +
            '<td data-label="Home visits">' + esc(f.home_visit_label || 'No home visits yet') + '</td>' +
            '<td data-label="Actions"><div class="bhw-fu-actions">' +
              '<button type="button" class="bhw-btn-outline bhw-fu-view" data-id="' + f.id + '">View</button>' +
              '<button type="button" class="bhw-btn-teal bhw-log-visit" data-id="' + f.id + '">Log visit</button>' +
            '</div></td>' +
          '</tr>';
        }).join('');

        tb.querySelectorAll('.bhw-fu-view').forEach(function (btn) {
          btn.addEventListener('click', function () {
            openDetail(parseInt(btn.dataset.id, 10));
          });
        });
        tb.querySelectorAll('.bhw-log-visit').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var id = parseInt(btn.dataset.id, 10);
            var row = rowsCache.find(function (x) { return parseInt(x.id, 10) === id; });
            if (row) openVisitModal(row);
          });
        });
      }
      if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
    }).catch(function () {
      tb.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty bhw-followup-empty--error">Could not load follow-ups.</td></tr>';
    });
  }

  document.getElementById('bhwVisitCancel').addEventListener('click', closeVisitModal);
  document.getElementById('bhwFuDetailClose').addEventListener('click', closeDetailModal);
  detailLogBtn.addEventListener('click', function () {
    if (activeDetail) {
      closeDetailModal();
      openVisitModal(activeDetail);
    }
  });

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
