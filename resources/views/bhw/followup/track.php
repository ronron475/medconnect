<?php
$page_title = 'Appointment and Follow-up Queue';
$bhw_current_file = 'followup/track.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require_once BASE_PATH . '/app/includes/bhw_workflows.php';

$bhwCtx = [
    'barangay_id' => (int) $bhw_barangay_id,
    'barangay_name' => $bhw_barangay_name,
    'allowed' => empty($bhw_no_sector),
];
// Server-render for this BHW's barangay so every account sees live data immediately.
$initialQueue = [];
try {
    $initialQueue = BhwWorkflows::listAppointmentFollowupQueue($pdo, $bhwCtx);
} catch (Throwable $e) {
    $initialQueue = [];
}

require __DIR__ . '/../partials/layout_open.php';

function bhw_afu_esc(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function bhw_afu_status_key(array $row): string
{
    return strtolower(preg_replace('/\s+/', '_', (string) ($row['status_key'] ?? 'unknown')));
}
?>
<div class="bhw-followup-page bhw-afu-queue" id="bhwAfuQueueRoot"
     data-barangay-id="<?= (int) $bhw_barangay_id ?>">
  <header class="bhw-followup-header bhw-page-intro">
    <p class="bhw-page-intro__desc">Live queue of appointments and follow-ups for residents in your assigned barangay.</p>
  </header>

  <div class="bhw-card bhw-followup-card">
    <div class="bhw-followup-toolbar bhw-afu-toolbar">
      <div class="bhw-dash-search bhw-afu-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="bhwAfuSearch" placeholder="Search by resident name…" aria-label="Search by resident name">
      </div>
      <span class="bhw-afu-live" id="bhwAfuLive" aria-live="polite">Live</span>
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
          <?php if (empty($initialQueue)): ?>
          <tr><td colspan="5" class="bhw-followup-empty">No appointments or follow-ups in your barangay yet.</td></tr>
          <?php else: ?>
          <?php foreach ($initialQueue as $row): ?>
          <tr>
            <td data-label="Patient ID">#<?= bhw_afu_esc((string) ($row['patient_id'] ?? '—')) ?></td>
            <td data-label="Patient Name"><span class="bhw-fu-patient-name"><?= bhw_afu_esc((string) ($row['patient_name'] ?? '—')) ?></span></td>
            <td data-label="Appointment Date"><?= bhw_afu_esc((string) ($row['appointment_date'] ?? '—')) ?></td>
            <td data-label="Appointment Time"><?= bhw_afu_esc((string) ($row['appointment_time'] ?? '—')) ?></td>
            <td data-label="Status"><span class="bhw-fu-status bhw-fu-status--<?= bhw_afu_esc(bhw_afu_status_key($row)) ?>"><?= bhw_afu_esc((string) ($row['status'] ?? '—')) ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script type="application/json" id="bhwAfuInitialQueue"><?= json_encode($initialQueue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<?php
ob_start();
?>
(function () {
  var tbody = document.getElementById('bhwAfuBody');
  var searchEl = document.getElementById('bhwAfuSearch');
  var liveEl = document.getElementById('bhwAfuLive');
  var initialEl = document.getElementById('bhwAfuInitialQueue');
  var allRows = [];
  var pollTimer = null;
  var loading = false;
  var lastFp = '';

  try {
    allRows = initialEl ? (JSON.parse(initialEl.textContent || '[]') || []) : [];
  } catch (e) {
    allRows = [];
  }
  lastFp = stableFp(allRows);

  function stableFp(value) {
    try { return JSON.stringify(value || null); } catch (e) { return ''; }
  }

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

  function setLive(state) {
    if (!liveEl) return;
    liveEl.textContent = state === 'error' ? 'Offline' : (state === 'updating' ? 'Updating…' : 'Live');
    liveEl.className = 'bhw-afu-live' + (state === 'error' ? ' is-offline' : '');
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
    if (typeof BhwPortal === 'undefined' || !BhwPortal.get) {
      setLive('error');
      return;
    }
    if (loading) return;
    loading = true;
    if (!silent) setLive('updating');
    BhwPortal.get('followups.php', { action: 'queue' }).then(function (r) {
      loading = false;
      if (!r.success) {
        setLive('error');
        if (!silent && !allRows.length) {
          tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty bhw-followup-empty--error">' +
            esc(r.message || 'Could not load queue.') + '</td></tr>';
        }
        return;
      }
      var next = r.queue || [];
      var fp = stableFp(next);
      if (fp !== lastFp) {
        lastFp = fp;
        allRows = next;
        render();
      }
      setLive('live');
      if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
    }).catch(function () {
      loading = false;
      setLive('error');
      if (!silent && !allRows.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty bhw-followup-empty--error">Could not load queue.</td></tr>';
      }
    });
  }

  if (searchEl) {
    searchEl.addEventListener('input', render);
  }

  // Keep every BHW barangay queue live: poll + hub + tab focus.
  loadQueue(true);
  pollTimer = window.setInterval(function () {
    if (document.hidden) return;
    if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 3000) return;
    loadQueue(true);
  }, 8000);

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
<?php
$bhw_inline_script = ob_get_clean();
require __DIR__ . '/../partials/layout_close.php';
?>
