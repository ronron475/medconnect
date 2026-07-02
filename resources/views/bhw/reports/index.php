<?php
$page_title = 'Reports';
$bhw_current_file = 'reports/index.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
$reports_css_ver = (int) @filemtime(ASSETS_PATH . '/css/bhw-reports.css');
$reports_js_ver = (int) @filemtime(ASSETS_PATH . '/js/bhw-reports.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-reports.css?v=<?= $reports_css_ver ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script src="<?= ASSET_BASE ?>/assets/js/bhw-reports.js?v=<?= $reports_js_ver ?>" defer></script>

<div class="bhw-reports-page" id="bhwReportsRoot" data-barangay="<?= $barangay_label ?>">

  <header class="bhw-reports-header">
    <div>
      <h2 class="text-h2">Healthcare Reports</h2>
      <p class="bhw-reports-sub">Barangay-scoped statistics for <strong>Brgy. <?= $barangay_label ?></strong>. Data is limited to patients in your assigned sector.</p>
    </div>
    <div class="bhw-reports-export-btns no-print">
      <button type="button" class="bhw-btn-outline" data-export="csv" title="Download CSV">CSV</button>
      <button type="button" class="bhw-btn-outline" data-export="excel" title="Download Excel file">Excel</button>
      <button type="button" class="bhw-btn-outline" data-export="print" title="Print report">Print</button>
    </div>
  </header>

  <div class="bhw-card bhw-reports-filters no-print">
    <div class="bhw-reports-filter-grid">
      <div>
        <label class="form-label" for="rf_date_from">Date from</label>
        <input type="date" class="form-control" id="rf_date_from">
      </div>
      <div>
        <label class="form-label" for="rf_date_to">Date to</label>
        <input type="date" class="form-control" id="rf_date_to">
      </div>
      <div>
        <label class="form-label" for="rf_month">Month</label>
        <input type="month" class="form-control" id="rf_month">
      </div>
      <div>
        <label class="form-label" for="rf_year">Year</label>
        <input type="number" class="form-control" id="rf_year" min="2020" max="2099" placeholder="<?= date('Y') ?>">
      </div>
      <div>
        <label class="form-label" for="rf_purok">Purok</label>
        <select class="form-select" id="rf_purok"><option value="">All puroks</option></select>
      </div>
      <div>
        <label class="form-label" for="rf_gender">Gender</label>
        <select class="form-select" id="rf_gender">
          <option value="">All</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
        </select>
      </div>
      <div>
        <label class="form-label" for="rf_age">Age group</label>
        <select class="form-select" id="rf_age">
          <option value="">All ages</option>
          <option value="children">Children (0–12)</option>
          <option value="teens">Teens (13–17)</option>
          <option value="adults">Adults (18–59)</option>
          <option value="seniors">Seniors (60+)</option>
        </select>
      </div>
      <div class="bhw-reports-filter-actions">
        <button type="button" class="bhw-btn-teal" id="rf_apply">Apply Filters</button>
        <button type="button" class="bhw-btn-outline" id="rf_reset">Reset</button>
      </div>
    </div>
  </div>

  <div class="bhw-reports-summary" id="bhwSummaryCards" aria-live="polite">
    <div class="bhw-reports-skeleton-grid" id="bhwSummarySkeleton">
      <?php for ($i = 0; $i < 8; $i++): ?><div class="bhw-reports-skeleton-card"></div><?php endfor; ?>
    </div>
    <div class="row g-3" id="bhwSummaryRow" style="display:none;"></div>
  </div>

  <nav class="bhw-reports-tabs no-print" role="tablist" aria-label="Report categories">
    <button type="button" class="bhw-reports-tab is-active" data-tab="patients" role="tab">Patient Registration</button>
    <button type="button" class="bhw-reports-tab" data-tab="consultations" role="tab">Consultations</button>
    <button type="button" class="bhw-reports-tab" data-tab="triage" role="tab">AI Triage</button>
    <button type="button" class="bhw-reports-tab" data-tab="referrals" role="tab">Referrals</button>
    <button type="button" class="bhw-reports-tab" data-tab="followups" role="tab">Follow-up</button>
    <button type="button" class="bhw-reports-tab" data-tab="disease" role="tab">Disease Statistics</button>
  </nav>

  <div class="bhw-reports-panels">
    <section class="bhw-reports-panel is-active" data-panel="patients">
      <div class="row g-3">
        <div class="col-lg-8"><div class="bhw-card bhw-chart-card"><h3>Monthly Registration Trend</h3><canvas id="chart_pat_monthly" height="120"></canvas></div></div>
        <div class="col-lg-4"><div class="bhw-card bhw-chart-card"><h3>Gender Distribution</h3><canvas id="chart_pat_gender" height="200"></canvas></div></div>
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Age Distribution</h3><canvas id="chart_pat_age" height="160"></canvas></div></div>
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Purok Distribution</h3><canvas id="chart_pat_purok" height="160"></canvas></div></div>
      </div>
    </section>
    <section class="bhw-reports-panel" data-panel="consultations">
      <div class="row g-3">
        <div class="col-md-4"><div class="bhw-card bhw-chart-card"><h3>By Status</h3><canvas id="chart_con_status" height="200"></canvas></div></div>
        <div class="col-md-8"><div class="bhw-card bhw-chart-card"><h3>Monthly Consultations</h3><canvas id="chart_con_monthly" height="120"></canvas></div></div>
        <div class="col-12"><div class="bhw-card bhw-chart-card"><h3>Provider Distribution</h3><canvas id="chart_con_provider" height="100"></canvas></div></div>
      </div>
    </section>
    <section class="bhw-reports-panel" data-panel="triage">
      <div class="row g-3">
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Risk Levels</h3><canvas id="chart_tri_urgency" height="180"></canvas></div></div>
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>AI Classifications</h3><canvas id="chart_tri_class" height="180"></canvas></div></div>
        <div class="col-12"><div class="bhw-card bhw-chart-card"><h3>Most Common Symptoms</h3><canvas id="chart_tri_symptoms" height="100"></canvas></div></div>
      </div>
    </section>
    <section class="bhw-reports-panel" data-panel="referrals">
      <div class="row g-3">
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Referral Types</h3><canvas id="chart_ref_type" height="180"></canvas></div></div>
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Referral Status</h3><canvas id="chart_ref_status" height="180"></canvas></div></div>
      </div>
    </section>
    <section class="bhw-reports-panel" data-panel="followups">
      <div class="row g-3">
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Follow-up Overview</h3><canvas id="chart_fol_overview" height="180"></canvas></div></div>
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Home Visit Types</h3><canvas id="chart_fol_visits" height="180"></canvas></div></div>
        <div class="col-12"><div class="bhw-card"><h3>Patients Requiring Follow-up</h3><div id="fol_requiring_list" class="bhw-reports-table-wrap"></div></div></div>
      </div>
    </section>
    <section class="bhw-reports-panel" data-panel="disease">
      <div class="row g-3">
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Top Conditions</h3><canvas id="chart_dis_conditions" height="160"></canvas></div></div>
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Top Symptoms (Triage)</h3><canvas id="chart_dis_symptoms" height="160"></canvas></div></div>
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Age Groups</h3><canvas id="chart_dis_age" height="160"></canvas></div></div>
        <div class="col-md-6"><div class="bhw-card bhw-chart-card"><h3>Monthly Triage Trend</h3><canvas id="chart_dis_monthly" height="160"></canvas></div></div>
      </div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
