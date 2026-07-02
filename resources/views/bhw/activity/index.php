<?php
$page_title = 'My Activity Log';
$bhw_current_file = 'activity/index.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$reports_css_ver = (int) @filemtime(ASSETS_PATH . '/css/bhw-reports.css');
$activity_js_ver = (int) @filemtime(ASSETS_PATH . '/js/bhw-activity.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-reports.css?v=<?= $reports_css_ver ?>">
<script src="<?= ASSET_BASE ?>/assets/js/bhw-activity.js?v=<?= $activity_js_ver ?>" defer></script>

<div class="bhw-activity-page" id="bhwActivityRoot">

  <header class="bhw-reports-header">
    <div>
      <h2 class="text-h2">My Activity Log</h2>
      <p class="bhw-reports-sub">A personal record of your actions in the BHW portal. You can only view your own activity — logs cannot be edited or deleted.</p>
    </div>
    <div class="bhw-reports-export-btns no-print">
      <button type="button" class="bhw-btn-outline" data-export="csv" title="Download CSV">CSV</button>
      <button type="button" class="bhw-btn-outline" data-export="excel" title="Download Excel file">Excel</button>
      <button type="button" class="bhw-btn-outline" data-export="print" title="Print activity log">Print</button>
    </div>
  </header>

  <div class="bhw-card bhw-reports-filters">
    <div class="bhw-reports-filter-grid bhw-activity-filters">
      <div class="span-2">
        <label class="form-label" for="act_search">Search</label>
        <input type="search" class="form-control" id="act_search" placeholder="Patient name, action, module…">
      </div>
      <div>
        <label class="form-label" for="act_period">Period</label>
        <select class="form-select" id="act_period">
          <option value="">All time</option>
          <option value="today">Today</option>
          <option value="week">This week</option>
          <option value="month">This month</option>
        </select>
      </div>
      <div>
        <label class="form-label" for="act_module">Module</label>
        <select class="form-select" id="act_module"><option value="">All modules</option></select>
      </div>
      <div>
        <label class="form-label" for="act_date_from">From</label>
        <input type="date" class="form-control" id="act_date_from">
      </div>
      <div>
        <label class="form-label" for="act_date_to">To</label>
        <input type="date" class="form-control" id="act_date_to">
      </div>
      <div class="bhw-reports-filter-actions">
        <button type="button" class="bhw-btn-teal" id="act_apply">Apply</button>
        <button type="button" class="bhw-btn-outline" id="act_reset">Reset</button>
      </div>
    </div>
  </div>

  <div class="bhw-card bhw-activity-table-card">
    <div class="bhw-activity-table-wrap">
      <table class="table bhw-table bhw-activity-table" id="actTable">
        <thead>
          <tr>
            <th>Date</th>
            <th>Time</th>
            <th>Action</th>
            <th>Patient</th>
            <th>Module</th>
            <th>IP</th>
            <th>Device</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="actBody">
          <tr><td colspan="8" class="text-center text-muted py-4">Loading activity…</td></tr>
        </tbody>
      </table>
    </div>
    <div class="bhw-activity-cards" id="actCards" aria-label="Activity on mobile"></div>
    <nav class="bhw-activity-pagination" id="actPagination" aria-label="Activity pagination"></nav>
  </div>
</div>

<div class="bhw-activity-modal" id="actModal" aria-hidden="true" role="dialog" aria-labelledby="actModalTitle">
  <div class="bhw-activity-modal-backdrop" data-close-modal></div>
  <div class="bhw-activity-modal-dialog">
    <button type="button" class="bhw-activity-modal-close" data-close-modal aria-label="Close">&times;</button>
    <h3 id="actModalTitle">Activity Details</h3>
    <dl class="bhw-activity-detail" id="actModalBody"></dl>
  </div>
</div>

<?php require __DIR__ . '/../partials/layout_close.php'; ?>
