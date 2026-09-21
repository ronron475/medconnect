<?php
/**
 * Live analytics charts — 2×2 User Overview layout (Admin + Super Admin).
 * Expects: $pdo
 */
if (!isset($pdo)) {
    return;
}

require_once BASE_PATH . '/app/includes/admin_dashboard_charts.php';

$chart_days = admin_chart_normalize_period_days((int) ($chart_days ?? 180));
$chart_period_label = admin_chart_period_label($chart_days);
$chart_js_ver = (int) @filemtime(ASSETS_PATH . '/js/admin-dashboard-charts.js');
$chart_theme_js_ver = (int) @filemtime(ASSETS_PATH . '/js/medconnect-chart-theme.js');
$chart_theme_css_ver = (int) @filemtime(ASSETS_PATH . '/css/medconnect-charts.css');
$chart_ui_css_ver = (int) @filemtime(ASSETS_PATH . '/css/admin-dashboard-charts.css');
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/medconnect-charts.css?v=<?= $chart_theme_css_ver ?>">
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-dashboard-charts.css?v=<?= $chart_ui_css_ver ?>">

<section class="adm-charts-section adm-overview-section" id="admChartsRoot" data-days="<?= $chart_days ?>" aria-label="User overview and system status">
  <div class="adm-charts-grid adm-overview-grid">
    <article class="adm-chart-card adm-overview-card">
      <div class="adm-chart-card__head">
        <div>
          <h3 class="adm-chart-card__title">User Overview</h3>
          <p class="adm-chart-card__sub">Total users in the system</p>
        </div>
      </div>
      <div class="adm-overview-tiles">
        <div class="adm-overview-tile adm-overview-tile--total">
          <div class="adm-overview-tile__icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div class="adm-overview-tile__value" id="admOverviewTotal" data-live-metric="total_users">—</div>
          <div class="adm-overview-tile__label">Total Users</div>
        </div>
        <div class="adm-overview-tile adm-overview-tile--doctors">
          <div class="adm-overview-tile__icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></svg>
          </div>
          <div class="adm-overview-tile__value" id="admOverviewDoctors" data-live-metric="providers">—</div>
          <div class="adm-overview-tile__label">Doctors</div>
        </div>
        <div class="adm-overview-tile adm-overview-tile--bhw">
          <div class="adm-overview-tile__icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          </div>
          <div class="adm-overview-tile__value" id="admOverviewBhw" data-live-metric="bhw">—</div>
          <div class="adm-overview-tile__label">BHW</div>
        </div>
        <div class="adm-overview-tile adm-overview-tile--admins">
          <div class="adm-overview-tile__icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
          </div>
          <div class="adm-overview-tile__value" id="admOverviewAdmins" data-live-metric="admins">—</div>
          <div class="adm-overview-tile__label">Administrators</div>
        </div>
      </div>
    </article>

    <article class="adm-chart-card">
      <div class="adm-chart-card__head">
        <div>
          <h3 class="adm-chart-card__title">User Registration Trends</h3>
          <p class="adm-chart-card__sub" id="admChartRegSub">New user sign-ups — <?= htmlspecialchars($chart_period_label) ?></p>
        </div>
        <div class="adm-chart-period">
          <label class="visually-hidden" for="admChartsDays">Period</label>
          <select id="admChartsDays" class="form-select adm-chart-period__select" aria-label="Registration trend period">
            <option value="30"<?= $chart_days === 30 ? ' selected' : '' ?>>Last Month</option>
            <option value="180"<?= $chart_days === 180 ? ' selected' : '' ?>>Last 6 Months</option>
            <option value="365"<?= $chart_days === 365 ? ' selected' : '' ?>>Last Year</option>
          </select>
        </div>
      </div>
      <div class="adm-chart-canvas-wrap adm-chart-canvas-wrap--trend">
        <canvas id="admChartReg" aria-label="User registration trends chart"></canvas>
      </div>
      <span id="admChartsUpdated" class="visually-hidden">Loading…</span>
    </article>

    <article class="adm-chart-card">
      <div class="adm-chart-card__head">
        <div>
          <h3 class="adm-chart-card__title">User Distribution</h3>
          <p class="adm-chart-card__sub">Accounts by role</p>
        </div>
      </div>
      <div class="adm-chart-canvas-wrap adm-chart-canvas-wrap--roles">
        <canvas id="admChartRoles" aria-label="User distribution chart"></canvas>
      </div>
    </article>

    <article class="adm-chart-card">
      <div class="adm-chart-card__head">
        <div>
          <h3 class="adm-chart-card__title">System Status</h3>
          <p class="adm-chart-card__sub">Overall system health</p>
        </div>
      </div>
      <div class="adm-sys-status">
        <div class="adm-sys-status__chart-wrap">
          <canvas id="admChartSysStatus" aria-label="System status chart"></canvas>
          <div class="adm-sys-status__center">
            <strong id="admSysOpPct">—</strong>
            <span>Operational</span>
          </div>
        </div>
        <ul class="adm-sys-status__list" id="admSysStatusList" aria-label="System status breakdown"></ul>
      </div>
    </article>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-chart-theme.js?v=<?= $chart_theme_js_ver ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-dashboard-charts.js?v=<?= $chart_js_ver ?>"></script>
