<?php
/**
 * BHW sector dashboard — live SQL metrics and triage queue (barangay-scoped).
 */
$page_title = 'Dashboard';
$bhw_current_file = 'dashboard.php';
require __DIR__ . '/partials/bhw_bootstrap.php';
require_once BASE_PATH . '/app/includes/bhw_workflows.php';
require_once BASE_PATH . '/app/includes/nav/bhw_nav.php';

$dashboard_nav = bhw_nav_dashboard();
$page_title = $dashboard_nav['label'];
$page_description = $dashboard_nav['description'];

$bhwCtx = [
    'barangay_id' => (int) $bhw_barangay_id,
    'barangay_name' => $bhw_barangay_name,
    'allowed' => empty($bhw_no_sector),
];
$stationBarangayName = $bhw_barangay_name;
$dashFilters = ['days' => 7];
// Unassigned BHW (barangay_id=0) uses deny-all SQL → live zeros, same UI shell.
$metricsRaw = BhwWorkflows::getDashboardMetrics($pdo, $bhwCtx, $dashFilters);
$dashboardCharts = BhwWorkflows::getDashboardCharts($pdo, $bhwCtx, $dashFilters);
$queueRaw = BhwWorkflows::getTriageQueue($pdo, $bhwCtx, 15, $dashFilters);

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

$bhwDashCss = ASSETS_PATH . '/css/bhw-dashboard.css';
$bhwDashCssVer = file_exists($bhwDashCss) ? (int) filemtime($bhwDashCss) : time();
$chartThemeJsVer = (int) @filemtime(ASSETS_PATH . '/js/medconnect-chart-theme.js');
$bhwDashChartsJsVer = (int) @filemtime(ASSETS_PATH . '/js/bhw-dashboard-charts.js');

require __DIR__ . '/partials/layout_open.php';
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-dashboard.css?v=<?= $bhwDashCssVer ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-chart-theme.js?v=<?= $chartThemeJsVer ?>" defer></script>
<script src="<?= ASSET_BASE ?>/assets/js/bhw-dashboard-charts.js?v=<?= $bhwDashChartsJsVer ?>" defer></script>
<script type="application/json" id="bhwDashChartsData"><?= json_encode($dashboardCharts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<div class="bhw-dash">

  <header class="bhw-dash-header">
    <div class="bhw-dash-header__main">
      <p class="bhw-dash-header__eyebrow">Barangay Health Operations</p>
      <h2 class="bhw-dash-header__title">Dashboard — Brgy. <?= htmlspecialchars($bhw_barangay_name) ?></h2>
      <p class="bhw-dash-header__desc"><?= htmlspecialchars($dashboard_nav['description']) ?></p>
    </div>
    <div class="bhw-dash-header__meta">
      <span class="bhw-dash-sync">Data refreshed: <time id="bhwLastSync"><?= date('h:i A') ?></time> · Auto-refresh 15s</span>
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

  <section class="bhw-dash-panel bhw-dash-charts" aria-labelledby="bhwDashChartsTitle">
    <div class="bhw-dash-panel__head">
      <h3 id="bhwDashChartsTitle">Sector analytics</h3>
      <span class="bhw-dash-panel__note" id="bhwDashChartsNote">Last 7 days · Brgy. <?= htmlspecialchars($bhw_barangay_name) ?></span>
    </div>
    <div class="mc-chart-filters bhw-dash-chart-filters no-print">
      <div class="mc-chart-filters__field mc-chart-filters__field--station">
        <span class="mc-chart-filters__label">Your station</span>
        <p class="bhw-dash-station-name" id="bhwDashStationName">Brgy. <?= htmlspecialchars($bhw_barangay_name) ?></p>
      </div>
      <div class="mc-chart-filters__field">
        <label class="mc-chart-filters__label" for="bhw_dash_days">Period</label>
        <select class="form-select mc-chart-filters__control" id="bhw_dash_days" aria-label="Chart period in days">
          <option value="1">Today</option>
          <option value="7" selected>Last 7 days</option>
          <option value="14">Last 14 days</option>
          <option value="30">Last 30 days</option>
          <option value="90">Last 90 days</option>
        </select>
      </div>
      <div class="mc-chart-filters__actions">
        <button type="button" class="bhw-btn-teal" id="bhw_dash_apply">Apply</button>
        <button type="button" class="bhw-btn-outline" id="bhw_dash_reset">Reset</button>
      </div>
    </div>
    <div class="bhw-dash-charts-grid">
      <article class="bhw-chart-card">
        <h4 id="bhw_dash_title_consult">Consultations</h4>
        <div class="bhw-chart-wrap bhw-chart-wrap--line"><canvas id="bhw_dash_consult_week" aria-label="Weekly consultations chart"></canvas></div>
      </article>
      <article class="bhw-chart-card">
        <h4 id="bhw_dash_title_reg">New registrations</h4>
        <div class="bhw-chart-wrap bhw-chart-wrap--line"><canvas id="bhw_dash_reg_week" aria-label="Weekly registrations chart"></canvas></div>
      </article>
      <article class="bhw-chart-card">
        <h4>Triage &amp; workflow risk</h4>
        <div class="bhw-chart-wrap bhw-chart-wrap--ring"><canvas id="bhw_dash_triage_mix" aria-label="Triage mix chart"></canvas></div>
      </article>
      <article class="bhw-chart-card">
        <h4>Patient workflow pipeline</h4>
        <div class="bhw-chart-wrap bhw-chart-wrap--bar-tall"><canvas id="bhw_dash_workflow" aria-label="Workflow pipeline chart"></canvas></div>
      </article>
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
    <div class="bhw-dash-panel__toolbar" role="search">
      <div class="bhw-dash-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="resident-search" placeholder="Search by resident name…" aria-label="Search residents in queue">
      </div>
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
                  <a href="patients/list.php">Open Patient List</a>.
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
  var dashDays = document.getElementById('bhw_dash_days');
  var stationBarangay = <?= json_encode('Brgy. ' . $stationBarangayName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  var tableBody = document.getElementById('queue-tbody');
  var lastSync = document.getElementById('bhwLastSync');
  var REFRESH_MS = (window.McChartTheme && McChartTheme.REFRESH_MS) ? McChartTheme.REFRESH_MS : 15000;

  function dashFilters() {
    return { days: dashDays ? dashDays.value : '7' };
  }

  function updateChartNote(payload) {
    var note = document.getElementById('bhwDashChartsNote');
    var days = (payload && payload.days) || (dashDays ? dashDays.value : 7);
    var periodText = (window.McChartTheme && McChartTheme.periodLabel)
      ? McChartTheme.periodLabel(days)
      : ('Last ' + days + ' days');
    var rangeText = (window.McChartTheme && McChartTheme.periodRangeLabel)
      ? McChartTheme.periodRangeLabel(days)
      : ('last ' + days + ' days');
    if (note) {
      note.textContent = periodText + ' · ' + stationBarangay;
    }
    var tc = document.getElementById('bhw_dash_title_consult');
    var tr = document.getElementById('bhw_dash_title_reg');
    if (tc) tc.textContent = 'Consultations — ' + rangeText;
    if (tr) tr.textContent = 'New registrations — ' + rangeText;
  }

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
      return '<tr class="' + rowClass(urgency) + '" data-name="' + esc(name.toLowerCase()) + '">' +
        '<td data-label="Resident">' +
          '<div class="bhw-dash-resident-name">' + esc(name) + '</div>' +
          '<div class="bhw-dash-resident-meta">' + esc(purok) + '</div>' +
        '</td>' +
        '<td data-label="Urgency"><span class="bhw-badge ' + badgeClass(urgency) + '">' + esc(String(urgency).toUpperCase()) + '</span></td>' +
        '<td data-label="Status"><span class="bhw-badge bhw-badge-scheduled">' + esc(status) + '</span></td>' +
        '<td class="text-end" data-label="Action">' +
          '<a class="bhw-btn-teal" href="patients/list.php?patient_id=' + encodeURIComponent(pid) + '">View Patient</a>' +
        '</td></tr>';
    }).join('');
    filterRows();
  }

  function filterRows() {
    var query = (searchInput.value || '').toLowerCase().trim();
    Array.from(tableBody.rows).forEach(function (row) {
      if (row.cells.length < 2) return;
      var name = row.dataset.name || '';
      row.style.display = !query || name.indexOf(query) >= 0 ? '' : 'none';
    });
  }

  function refreshDashboard() {
    if (document.hidden) return;
    BhwPortal.get('dashboard.php', dashFilters()).then(function (res) {
      if (!res.success) return;
      var m = res.metrics || {};
      document.querySelectorAll('[data-metric]').forEach(function (el) {
        var k = el.dataset.metric;
        if (m[k] !== undefined) el.textContent = m[k];
      });
      renderQueue(res.queue || []);
      if (res.charts && window.BhwDashboardCharts) {
        BhwDashboardCharts.update(res.charts);
        updateChartNote(res.charts);
      }
      if (lastSync) {
        lastSync.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
      }
    });
  }

  document.getElementById('bhw_dash_apply')?.addEventListener('click', refreshDashboard);
  document.getElementById('bhw_dash_reset')?.addEventListener('click', function () {
    if (dashDays) dashDays.value = '7';
    refreshDashboard();
  });
  dashDays?.addEventListener('change', refreshDashboard);

  searchInput.addEventListener('input', filterRows);
  if (initialQueue.length) renderQueue(initialQueue);
  updateChartNote(<?= json_encode($dashboardCharts) ?>);
  window.refreshBhwDashboard = refreshDashboard;

  var dashTimer = setInterval(function () {
    if (document.hidden) return;
    if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
    refreshDashboard();
  }, REFRESH_MS);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refreshDashboard();
  });
  document.addEventListener('medconnect:live-sync', function (ev) {
    var changed = (ev.detail && ev.detail.changed) || [];
    if (changed.indexOf('triage') !== -1 || changed.indexOf('queue') !== -1 || changed.indexOf('appointments') !== -1) {
      refreshDashboard();
    }
  });
})();
<?php
$bhw_inline_script = ob_get_clean();
require __DIR__ . '/partials/layout_close.php';
?>
