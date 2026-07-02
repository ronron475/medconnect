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
    ['label' => 'Total Households', 'val' => (int) ($metricsRaw['total_households'] ?? 0), 'icon' => 'home', 'key' => 'total_households'],
    ['label' => 'Pending Triage',   'val' => (int) ($metricsRaw['pending_triage'] ?? 0),   'icon' => 'clipboard', 'key' => 'pending_triage'],
    ['label' => 'Scheduled Calls',  'val' => (int) ($metricsRaw['scheduled_calls'] ?? 0),  'icon' => 'video', 'key' => 'scheduled_calls'],
    ['label' => 'High-Risk Flags',  'val' => (int) ($metricsRaw['high_risk_flags'] ?? 0),  'icon' => 'alert-circle', 'key' => 'high_risk_flags'],
];

$puroks = [];
foreach ($queueRaw as $row) {
    $p = trim((string) ($row['purok'] ?? ''));
    if ($p !== '' && !in_array($p, $puroks, true)) {
        $puroks[] = $p;
    }
}
sort($puroks);

require __DIR__ . '/partials/layout_open.php';
$notif_widget_mode = 'strip';
require VIEWS_PATH . '/partials/notification_widgets.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
  <div>
    <h2 class="text-h2" style="color: var(--bhw-navy); font-weight: 800; margin-bottom: 6px;">
      <?= htmlspecialchars($dashboard_nav['label']) ?> — Brgy. <?= htmlspecialchars($bhw_barangay_name) ?>
    </h2>
    <p style="color: var(--bhw-teal); font-size: 14px; font-weight: 600; margin: 0;">
      <?= htmlspecialchars($dashboard_nav['description']) ?>
    </p>
  </div>
  <div class="text-muted" style="font-size: 11px; font-weight: 600;">Last Sync: <span id="bhwLastSync"><?= date('h:i A') ?></span></div>
</div>

<div class="row g-3 mb-4" id="bhwMetricsRow">
  <?php foreach ($metrics as $m): ?>
  <div class="col-md-3">
    <div class="bhw-metric-card">
      <div class="bhw-metric-info">
        <div class="bhw-metric-label"><?= htmlspecialchars($m['label']) ?></div>
        <div class="bhw-metric-val" data-metric="<?= htmlspecialchars($m['key']) ?>"><?= $m['val'] ?></div>
      </div>
      <div class="bhw-metric-icon">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <?php if ($m['icon'] === 'home'): ?><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/><?php endif; ?>
          <?php if ($m['icon'] === 'clipboard'): ?><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><?php endif; ?>
          <?php if ($m['icon'] === 'video'): ?><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/><?php endif; ?>
          <?php if ($m['icon'] === 'alert-circle'): ?><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/><?php endif; ?>
        </svg>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row">
  <div class="col-12">
    <div class="bhw-card" style="padding: 0; overflow: hidden; background-color: var(--bhw-canvas);">
      <div style="padding: 24px 24px 16px; border-bottom: 1px solid var(--mc-border-thin); background-color: #fff;">
        <div class="row align-items-center">
          <div class="col-md-6">
            <h3 class="text-h3" style="margin:0; font-size: 1.1rem; color: var(--bhw-navy); font-weight: 800;">Barangay Triage and Scheduling Queue</h3>
          </div>
          <div class="col-md-6 text-end">
            <span class="text-muted" style="font-size: 10px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase;">LOGISTICAL VIEW ONLY (RA 10173 COMPLIANT)</span>
          </div>
        </div>
        <div class="row mt-3 g-2">
          <div class="col-md-4">
            <div class="input-group shadow-sm" style="border-radius: 8px; overflow: hidden;">
              <span class="input-group-text bg-white border-end-0" style="color: #94a3b8; padding-left: 16px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              </span>
              <input type="text" id="resident-search" class="form-control border-start-0" placeholder="Search resident…" style="font-size: 13px; padding: 10px 12px; font-weight: 500;">
            </div>
          </div>
          <div class="col-md-3 ms-auto">
            <select id="purok-filter" class="form-select shadow-sm" style="border-radius: 8px; font-size: 13px; font-weight: 600; padding: 10px 12px;">
              <option value="">All Puroks</option>
              <?php foreach ($puroks as $purok): ?>
              <option><?= htmlspecialchars($purok) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="table-responsive" style="background-color: var(--bhw-canvas);">
        <table class="table bhw-table" style="margin:0; border-collapse: separate; border-spacing: 0 8px;">
          <thead>
            <tr style="background-color: #fff;">
              <th style="padding-left: 24px; border: none;">Resident & Purok</th>
              <th style="border: none;">Urgency</th>
              <th style="border: none;">Status</th>
              <th class="text-end" style="padding-right: 24px; border: none;">Actions</th>
            </tr>
          </thead>
          <tbody id="queue-tbody">
            <?php if (empty($queueRaw)): ?>
            <tr><td colspan="4" style="padding: 24px; text-align: center; color: #64748b;">No triage records in your barangay yet. <a href="patients/register.php">Register a patient</a> or <a href="triage/submit.php">submit triage</a>.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="bhw-dashboard-recent">
<?php
$notif_widget_mode = 'recent';
require VIEWS_PATH . '/partials/notification_widgets.php';
?>
</div>

<script>
(function () {
  var initialQueue = <?= json_encode($queueRaw, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  var searchInput = document.getElementById('resident-search');
  var purokFilter = document.getElementById('purok-filter');
  var tableBody = document.getElementById('queue-tbody');
  var lastSync = document.getElementById('bhwLastSync');

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
      tableBody.innerHTML = '<tr><td colspan="4" style="padding:24px;text-align:center;color:#64748b;">No triage records match your filters.</td></tr>';
      return;
    }
    tableBody.innerHTML = rows.map(function (r) {
      var name = (r.first_name || '') + ' ' + (r.last_name || '');
      var purok = r.purok || '—';
      var urgency = (r.urgency_label || 'low');
      var status = r.status || 'pending';
      var pid = r.patient_id || '';
      return '<tr class="' + rowClass(urgency) + '" style="background:#fff;" data-name="' + name.toLowerCase() + '" data-purok="' + purok + '">' +
        '<td style="padding-left:24px;"><div style="font-weight:800;color:var(--bhw-navy);">' + name + '</div>' +
        '<div class="text-muted" style="font-size:11px;font-weight:600;text-transform:uppercase;">' + purok + '</div></td>' +
        '<td><span class="bhw-badge ' + badgeClass(urgency) + '">' + urgency.toUpperCase() + '</span></td>' +
        '<td><span class="bhw-badge bhw-badge-scheduled">' + status + '</span></td>' +
        '<td class="text-end" style="padding-right:24px;">' +
        '<a class="bhw-btn-teal" href="triage/submit.php?patient_id=' + pid + '">Triage &amp; Book</a></td></tr>';
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
</script>

<?php require __DIR__ . '/partials/layout_close.php'; ?>
