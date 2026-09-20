<?php
$page_title = 'Appointment and Follow-up Queue';
$bhw_current_file = 'followup/track.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
?>
<div class="bhw-followup-page bhw-afu-queue" id="bhwAfuQueueRoot">
  <header class="bhw-followup-header bhw-page-intro">
    <p class="bhw-page-intro__desc">Live queue of appointments and follow-ups for residents in your assigned barangay.</p>
  </header>

  <div class="bhw-card bhw-followup-card">
    <div class="bhw-followup-toolbar bhw-afu-toolbar">
      <div class="bhw-dash-search bhw-afu-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="bhwAfuSearch" placeholder="Search by resident name…" aria-label="Search by resident name">
      </div>
    </div>
    <div class="table-responsive bhw-followup-table-wrap">
      <table class="table bhw-table bhw-followup-table bhw-afu-table mb-0">
        <thead>
          <tr>
            <th scope="col">Patient ID</th>
            <th scope="col">Patient Name</th>
            <th scope="col">Appointment Date</th>
            <th scope="col">Appointment Time</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody id="bhwAfuBody">
          <tr><td colspan="5" class="bhw-followup-empty">Loading queue…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function () {
  var tbody = document.getElementById('bhwAfuBody');
  var searchEl = document.getElementById('bhwAfuSearch');
  var allRows = [];
  var pollTimer = null;
  var loading = false;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function statusBadge(row) {
    var key = String(row.status_key || 'unknown').toLowerCase().replace(/\s+/g, '_');
    var label = row.status || '—';
    return '<span class="bhw-fu-status bhw-fu-status--' + esc(key) + '">' + esc(label) + '</span>';
  }

  function matchesSearch(row, q) {
    if (!q) return true;
    var name = String(row.patient_name || '').toLowerCase();
    var id = String(row.patient_id || '');
    return name.indexOf(q) !== -1 || id.indexOf(q) !== -1;
  }

  function render() {
    var q = (searchEl && searchEl.value ? searchEl.value : '').trim().toLowerCase();
    var rows = allRows.filter(function (r) { return matchesSearch(r, q); });
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty">' +
        (allRows.length ? 'No matching residents in the queue.' : 'No appointments or follow-ups in your barangay yet.') +
        '</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (r) {
      return '<tr>' +
        '<td data-label="Patient ID">#' + esc(r.patient_id || '—') + '</td>' +
        '<td data-label="Patient Name"><span class="bhw-fu-patient-name">' + esc(r.patient_name || '—') + '</span></td>' +
        '<td data-label="Appointment Date">' + esc(r.appointment_date || '—') + '</td>' +
        '<td data-label="Appointment Time">' + esc(r.appointment_time || '—') + '</td>' +
        '<td data-label="Status">' + statusBadge(r) + '</td>' +
      '</tr>';
    }).join('');
  }

  function loadQueue(silent) {
    if (loading) return;
    loading = true;
    if (!silent) {
      tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty">Loading queue…</td></tr>';
    }
    BhwPortal.get('followups.php', { action: 'queue' }).then(function (r) {
      loading = false;
      if (!r.success) {
        tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty bhw-followup-empty--error">' +
          esc(r.message || 'Could not load queue.') + '</td></tr>';
        return;
      }
      allRows = r.queue || [];
      render();
      if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
    }).catch(function () {
      loading = false;
      tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty bhw-followup-empty--error">Could not load queue.</td></tr>';
    });
  }

  if (searchEl) {
    searchEl.addEventListener('input', render);
  }

  loadQueue(false);

  pollTimer = window.setInterval(function () {
    if (document.hidden) return;
    if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
    loadQueue(true);
  }, 15000);

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) loadQueue(true);
  });

  document.addEventListener('medconnect:live-sync', function (ev) {
    var changed = (ev.detail && ev.detail.changed) || [];
    if (
      changed.indexOf('appointments') !== -1 ||
      changed.indexOf('queue') !== -1 ||
      changed.indexOf('consultations') !== -1 ||
      changed.indexOf('followups') !== -1 ||
      changed.indexOf('triage') !== -1
    ) {
      loadQueue(true);
    }
  });

  window.addEventListener('beforeunload', function () {
    if (pollTimer) window.clearInterval(pollTimer);
  });
})();
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
