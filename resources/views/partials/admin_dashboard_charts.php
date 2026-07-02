<?php
/**
 * Live analytics charts — data loaded via API + Chart.js auto-refresh.
 * Expects: $pdo
 */
if (!isset($pdo)) {
    return;
}

require_once BASE_PATH . '/app/includes/admin_dashboard_charts.php';

$chart_days = (int) ($chart_days ?? 30);
$chart_days = max(7, min(90, $chart_days));
$chart_api = ASSET_BASE . '/app/api/admin/dashboard_charts.php?days=' . $chart_days;
$chart_js_ver = (int) @filemtime(ASSETS_PATH . '/js/admin-dashboard-charts.js');
?>

<section class="adm-charts-section" id="admChartsRoot" data-days="<?= $chart_days ?>" aria-label="Analytics and trends">
  <div class="adm-section-head" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:8px;">
    <div>
      <h2 class="adm-section-title">Analytics &amp; Trends</h2>
      <p class="adm-section-sub">Live data from database — auto-refreshes every 45 seconds</p>
    </div>
    <span id="admChartsUpdated" class="text-xs text-muted">Loading…</span>
  </div>

  <div class="adm-charts-grid">
    <article class="adm-chart-card">
      <div class="adm-chart-card__head">
        <div>
          <h3 class="adm-chart-card__title">Consultations</h3>
          <p class="adm-chart-card__sub">Daily volume — last <?= $chart_days ?> days</p>
        </div>
        <div class="adm-chart-kpi">
          <strong id="admKpiConsultTotal">—</strong>
          <span>Period total</span>
        </div>
      </div>
      <div class="adm-chart-canvas-wrap">
        <canvas id="admChartConsult" aria-label="Consultations bar chart"></canvas>
      </div>
    </article>

    <article class="adm-chart-card">
      <div class="adm-chart-card__head">
        <div>
          <h3 class="adm-chart-card__title">New Registrations</h3>
          <p class="adm-chart-card__sub">User sign-ups — last 14 days</p>
        </div>
        <div class="adm-chart-kpi">
          <strong id="admKpiRegTotal">—</strong>
          <span>14-day total</span>
        </div>
      </div>
      <div class="adm-chart-canvas-wrap">
        <canvas id="admChartReg" aria-label="Registrations line chart"></canvas>
      </div>
    </article>

    <article class="adm-chart-card">
      <div class="adm-chart-card__head">
        <div>
          <h3 class="adm-chart-card__title">User Distribution</h3>
          <p class="adm-chart-card__sub">Accounts by role (live count)</p>
        </div>
        <div class="adm-chart-kpi">
          <strong id="admKpiUsersTotal">—</strong>
          <span>Total users</span>
        </div>
      </div>
      <div class="adm-chart-canvas-wrap">
        <canvas id="admChartRoles" aria-label="User distribution chart"></canvas>
      </div>
    </article>

    <article class="adm-chart-card">
      <div class="adm-chart-card__head">
        <div>
          <h3 class="adm-chart-card__title" id="admChartFourthTitle">Consultation Status</h3>
          <p class="adm-chart-card__sub" id="admChartFourthSub">Live breakdown by workflow state</p>
        </div>
        <div class="adm-chart-kpi">
          <strong id="admKpiFourthTotal">—</strong>
          <span id="admKpiFourthLabel">All consultations</span>
        </div>
      </div>
      <div class="adm-chart-canvas-wrap">
        <canvas id="admChartStatus" aria-label="Consultation status chart"></canvas>
        <canvas id="admChartTriage" aria-label="Triage volume chart" style="display:none;"></canvas>
      </div>
    </article>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-dashboard-charts.js?v=<?= $chart_js_ver ?>"></script>
