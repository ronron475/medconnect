<?php
/**
 * BHW sector dashboard — live SQL metrics and triage queue (barangay-scoped).
 */
$page_title = 'Dashboard';
$bhw_current_file = 'dashboard.php';
require __DIR__ . '/partials/bhw_bootstrap.php';
require_once BASE_PATH . '/app/includes/bhw_workflows.php';
require __DIR__ . '/partials/bhw_nav.php';

$dashboard_nav = bhw_nav_dashboard();
$page_title = $dashboard_nav['label'];
$page_description = $dashboard_nav['description'];

$metricsRaw = BhwWorkflows::getDashboardMetrics($pdo, [
    'barangay_id' => $bhw_barangay_id,
    'barangay_name' => $bhw_barangay_name,
    'allowed' => true,
]);
$queueRaw = BhwWorkflows::getTriageQueue($pdo, [
    'barangay_id' => $bhw_barangay_id,
    'barangay_name' => $bhw_barangay_name,
    'allowed' => true,
]);

$metrics = [
    ['label' => "Today's Patients", 'val' => (int) ($metricsRaw['todays_patients'] ?? 0), 'key' => 'todays_patients', 'tone' => ''],
    ['label' => 'Awaiting Complaint', 'val' => (int) ($metricsRaw['pending_registrations'] ?? 0), 'key' => 'pending_registrations', 'tone' => 'warn'],
    ['label' => 'Waiting AI Triage', 'val' => (int) ($metricsRaw['waiting_ai_triage'] ?? 0), 'key' => 'waiting_ai_triage', 'tone' => 'warn'],
    ['label' => 'Emergency Cases', 'val' => (int) ($metricsRaw['emergency_cases'] ?? 0), 'key' => 'emergency_cases', 'tone' => 'alert'],
    ['label' => 'Urgent Cases', 'val' => (int) ($metricsRaw['urgent_cases'] ?? 0), 'key' => 'urgent_cases', 'tone' => 'alert'],
    ['label' => 'Non-Urgent', 'val' => (int) ($metricsRaw['non_urgent_cases'] ?? 0), 'key' => 'non_urgent_cases', 'tone' => ''],
    ['label' => 'Upcoming Consults', 'val' => (int) ($metricsRaw['upcoming_consultations'] ?? 0), 'key' => 'upcoming_consultations', 'tone' => ''],
    ['label' => 'Referrals', 'val' => (int) ($metricsRaw['referrals'] ?? 0), 'key' => 'referrals', 'tone' => ''],
];

$puroks = [];
foreach ($queueRaw as $row) {
    $p = trim((string) ($row['purok'] ?? ''));
    if ($p !== '' && !in_array($p, $puroks, true)) {
        $puroks[] = $p;
    }
}
sort($puroks);

$bhwDashCss = ASSETS_PATH . '/css/bhw-dashboard.css';
$bhwDashCssVer = file_exists($bhwDashCss) ? (int) filemtime($bhwDashCss) : time();

require __DIR__ . '/partials/layout_open.php';
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-dashboard.css?v=<?= $bhwDashCssVer ?>">

<div class="bhw-dash">

  <header class="bhw-dash-header">
    <div class="bhw-dash-header__main">
      <p class="bhw-dash-header__eyebrow">Barangay Health Operations</p>
      <h2 class="bhw-dash-header__title">Dashboard — Brgy. <?= htmlspecialchars($bhw_barangay_name) ?></h2>
      <p class="bhw-dash-header__desc"><?= htmlspecialchars($dashboard_nav['description']) ?></p>
    </div>
    <div class="bhw-dash-header__meta">
      <span class="bhw-dash-sync">Data refreshed: <time id="bhwLastSync"><?= date('h:i A') ?></time></span>
    </div>
  </header>

  <section class="bhw-dash-panel" aria-labelledby="bhwDashIndicatorsTitle">
    <div class="bhw-dash-panel__head">
      <h3 id="bhwDashIndicatorsTitle">Sector Health Indicators</h3>
      <span class="bhw-dash-panel__note">Assigned barangay only</span>
    </div>
    <div class="bhw-dash-stats" id="bhwMetricsRow">
      <?php foreach ($metrics as $m):
        $toneClass = $m['tone'] !== '' ? ' bhw-dash-stat--' . $m['tone'] : '';
      ?>
      <article class="bhw-dash-stat<?= $toneClass ?>">
        <span class="bhw-dash-stat__label"><?= htmlspecialchars($m['label']) ?></span>
        <strong class="bhw-dash-stat__val" data-metric="<?= htmlspecialchars($m['key']) ?>"><?= $m['val'] ?></strong>
      </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="bhw-dash-panel" aria-labelledby="bhwDashOpsTitle">
    <div class="bhw-dash-panel__head">
      <h3 id="bhwDashOpsTitle">Operations Summary</h3>
      <span class="bhw-dash-panel__note">Live platform activity</span>
    </div>
    <div class="bhw-dash-ops">
      <?php
      $notif_widget_mode = 'strip';
      $notif_widget_bare = true;
      require VIEWS_PATH . '/partials/notification_widgets.php';
      ?>
    </div>
  </section>

  <section class="bhw-dash-panel bhw-dash-panel--queue" aria-labelledby="bhwDashQueueTitle">
    <div class="bhw-dash-panel__head">
      <h3 id="bhwDashQueueTitle">Triage &amp; Scheduling Queue</h3>
      <span class="bhw-dash-compliance">Logistical view · RA 10173 compliant</span>
    </div>
    <div class="bhw-dash-panel__toolbar">
      <div class="input-group bhw-dash-search">
        <span class="input-group-text" aria-hidden="true">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </span>
        <input type="search" id="resident-search" class="form-control" placeholder="Search resident name…" aria-label="Search residents in queue">
      </div>
      <select id="purok-filter" class="form-select bhw-dash-purok" aria-label="Filter by purok">
        <option value="">All puroks</option>
        <?php foreach ($puroks as $purok): ?>
        <option><?= htmlspecialchars($purok) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="bhw-dash-panel__body bhw-dash-panel__body--flush">
      <div class="table-responsive">
        <table class="bhw-dash-queue-table">
          <thead>
            <tr>
              <th scope="col">Resident</th>
              <th scope="col">Urgency</th>
              <th scope="col">Status</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody id="queue-tbody">
            <?php if (empty($queueRaw)): ?>
            <tr>
              <td colspan="4">
                <div class="bhw-dash-queue-empty">
                  No triage records in your barangay yet.
                  <a href="patients/register.php">Register a patient</a> or
                  <a href="triage/submit.php">submit triage</a>.
                </div>
              </td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <section class="bhw-dash-panel bhw-dash-recent" aria-label="Recent notifications">
    <?php
    $notif_widget_mode = 'recent';
    require VIEWS_PATH . '/partials/notification_widgets.php';
    ?>
  </section>

</div>

<?php
ob_start();
?>
(function () {
  var initialQueue = <?= json_encode($queueRaw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  var searchInput = document.getElementById('resident-search');
  var purokFilter = document.getElementById('purok-filter');
  var tableBody = document.getElementById('queue-tbody');
  var lastSync = document.getElementById('bhwLastSync');

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function badgeClass(urgency) {
    var u = (urgency || '').toLowerCase();
    if (u === 'high' || u.indexOf('urgent') >= 0) return 'bhw-badge-high';
    if (u === 'moderate') return 'bhw-badge-moderate';
    return 'bhw-badge-low';
  }

  function rowClass(urgency) {
    var u = (urgency || '').toLowerCase();
    if (u === 'high' || u.indexOf('urgent') >= 0) return 'bhw-row-high';
    if (u === 'moderate') return 'bhw-row-moderate';
    return '';
  }

  function renderQueue(rows) {
    if (!rows.length) {
      tableBody.innerHTML = '<tr><td colspan="4"><div class="bhw-dash-queue-empty">No triage records match your filters.</div></td></tr>';
      return;
    }
    tableBody.innerHTML = rows.map(function (r) {
      var name = ((r.first_name || '') + ' ' + (r.last_name || '')).trim();
      var purok = r.purok || '—';
      var urgency = (r.urgency_label || 'low');
      var status = r.status || 'pending';
      var pid = r.patient_id || '';
      return '<tr class="' + rowClass(urgency) + '" data-name="' + esc(name.toLowerCase()) + '" data-purok="' + esc(purok) + '">' +
        '<td data-label="Resident">' +
          '<div class="bhw-dash-resident-name">' + esc(name) + '</div>' +
          '<div class="bhw-dash-resident-meta">' + esc(purok) + '</div>' +
        '</td>' +
        '<td data-label="Urgency"><span class="bhw-badge ' + badgeClass(urgency) + '">' + esc(String(urgency).toUpperCase()) + '</span></td>' +
        '<td data-label="Status"><span class="bhw-badge bhw-badge-scheduled">' + esc(status) + '</span></td>' +
        '<td class="text-end" data-label="Action">' +
          '<a class="bhw-btn-teal" href="triage/submit.php?patient_id=' + encodeURIComponent(pid) + '">Triage &amp; Book</a>' +
        '</td></tr>';
    }).join('');
    filterRows();
  }

  function filterRows() {
    var query = (searchInput.value || '').toLowerCase().trim();
    var purok = purokFilter.value;
    Array.from(tableBody.rows).forEach(function (row) {
      if (row.cells.length < 2) return;
      var name = row.dataset.name || '';
      var rowPurok = row.dataset.purok || '';
      row.style.display = (!query || name.indexOf(query) >= 0) && (!purok || rowPurok === purok) ? '' : 'none';
    });
  }

  function refreshDashboard() {
    BhwPortal.get('dashboard.php').then(function (res) {
      if (!res.success) return;
      var m = res.metrics || {};
      document.querySelectorAll('[data-metric]').forEach(function (el) {
        var k = el.dataset.metric;
        if (m[k] !== undefined) el.textContent = m[k];
      });
      renderQueue(res.queue || []);
      if (lastSync) {
        lastSync.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
      }
    });
  }

  searchInput.addEventListener('input', filterRows);
  purokFilter.addEventListener('change', filterRows);
  if (initialQueue.length) renderQueue(initialQueue);
  setInterval(refreshDashboard, 30000);
})();
<?php
$bhw_inline_script = ob_get_clean();
require __DIR__ . '/partials/layout_close.php';
?>
