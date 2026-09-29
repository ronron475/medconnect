<?php
/**
 * BHW sector dashboard — live barangay consultation trends and notifications.
 */
$page_title = 'Dashboard';
$bhw_current_file = 'dashboard.php';
require __DIR__ . '/partials/bhw_bootstrap.php';
require_once BASE_PATH . '/app/includes/bhw_workflows.php';
require_once BASE_PATH . '/app/includes/nav/bhw_nav.php';

$dashboard_nav = bhw_nav_dashboard();
$page_title = $dashboard_nav['label'];

$bhwCtx = [
    'barangay_id' => (int) $bhw_barangay_id,
    'barangay_name' => $bhw_barangay_name,
    'allowed' => empty($bhw_no_sector),
];
$dashFilters = ['days' => 7];
// Unassigned BHW (barangay_id=0) uses deny-all SQL → live zeros, same UI shell.
$dashboardCharts = BhwWorkflows::getDashboardCharts($pdo, $bhwCtx, $dashFilters);

$bhwDashCss = ASSETS_PATH . '/css/bhw-dashboard.css';
$bhwDashCssVer = file_exists($bhwDashCss) ? (int) filemtime($bhwDashCss) : time();
$bhw_head_css = ASSET_BASE . '/assets/css/bhw-dashboard.css?v=' . $bhwDashCssVer;
$chartThemeJsVer = (int) @filemtime(ASSETS_PATH . '/js/medconnect-chart-theme.js');
$bhwDashChartsJsVer = (int) @filemtime(ASSETS_PATH . '/js/bhw-dashboard-charts.js');

require __DIR__ . '/partials/layout_open.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-chart-theme.js?v=<?= $chartThemeJsVer ?>" defer></script>
<script src="<?= ASSET_BASE ?>/assets/js/bhw-dashboard-charts.js?v=<?= $bhwDashChartsJsVer ?>" defer></script>
<script type="application/json" id="bhwDashChartsData"><?= json_encode($dashboardCharts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<div class="bhw-dash">

  <section class="bhw-dash-panel bhw-dash-charts" id="bhwDashChartsRoot" data-days="7" aria-label="Activity charts">
    <div class="bhw-dash-charts-toolbar no-print">
      <div class="bhw-dash-charts-toolbar__title">
        <h3 class="bhw-dash-charts-heading">Activity</h3>
        <p class="bhw-dash-charts-sub">Live barangay trends</p>
      </div>
      <div class="bhw-dash-period">
        <button type="button" id="bhw_dash_period_btn" class="bhw-dash-period__btn" aria-haspopup="listbox" aria-expanded="false" aria-controls="bhw_dash_period_menu">Week</button>
        <ul id="bhw_dash_period_menu" class="bhw-dash-period__menu" role="listbox" aria-label="Chart date range" hidden>
          <li role="presentation"><button type="button" role="option" data-value="1" aria-selected="false">Days</button></li>
          <li role="presentation"><button type="button" role="option" data-value="7" aria-selected="true">Weeks</button></li>
          <li role="presentation"><button type="button" role="option" data-value="30" aria-selected="false">Months</button></li>
        </ul>
        <input type="hidden" id="bhw_dash_days" value="7">
      </div>
    </div>
    <div class="bhw-dash-charts-grid">
      <article class="bhw-chart-card">
        <h4 id="bhw_dash_title_consult">Consultation trends</h4>
        <div class="bhw-chart-wrap bhw-chart-wrap--line"><canvas id="bhw_dash_consult_week" aria-label="Consultation trends chart"></canvas></div>
      </article>
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
  var dashDays = document.getElementById('bhw_dash_days');
  var periodBtn = document.getElementById('bhw_dash_period_btn');
  var periodMenu = document.getElementById('bhw_dash_period_menu');
  var chartsRoot = document.getElementById('bhwDashChartsRoot');
  var REFRESH_MS = (window.McChartTheme && McChartTheme.REFRESH_MS) ? McChartTheme.REFRESH_MS : 15000;
  var periodButtonLabels = { '1': 'Days', '7': 'Week', '30': 'Months' };
  var lastChartsFp = '';

  function dashFilters() {
    return { days: dashDays ? dashDays.value : '7' };
  }

  function stableFp(value) {
    try {
      return JSON.stringify(value || null);
    } catch (e) {
      return '';
    }
  }

  function setPeriodOpen(open) {
    if (!periodBtn || !periodMenu) return;
    periodBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    periodMenu.hidden = !open;
  }

  function applyPeriod(value, refresh) {
    var next = String(value || '7');
    if (dashDays) dashDays.value = next;
    if (periodBtn) periodBtn.textContent = periodButtonLabels[next] || 'Week';
    if (periodMenu) {
      periodMenu.querySelectorAll('[role="option"]').forEach(function (opt) {
        opt.setAttribute('aria-selected', opt.getAttribute('data-value') === next ? 'true' : 'false');
      });
    }
    if (chartsRoot) chartsRoot.setAttribute('data-days', next);
    if (refresh) {
      lastChartsFp = '';
      refreshDashboard();
    }
  }

  function refreshNotifications() {
    if (window.MedConnectNotifications && typeof MedConnectNotifications.refreshWidgets === 'function') {
      MedConnectNotifications.refreshWidgets();
    }
  }

  function refreshDashboard() {
    if (document.hidden || !window.BhwPortal) return;
    BhwPortal.get('dashboard.php', dashFilters()).then(function (res) {
      if (!res.success || !res.charts || !window.BhwDashboardCharts) return;
      var chartsFp = stableFp(res.charts);
      if (chartsFp !== lastChartsFp || !lastChartsFp) {
        lastChartsFp = chartsFp;
        BhwDashboardCharts.update(res.charts);
      }
    }).catch(function () {});
    refreshNotifications();
  }

  if (periodBtn && periodMenu) {
    periodBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      setPeriodOpen(periodMenu.hidden);
    });
    periodMenu.addEventListener('click', function (e) {
      var opt = e.target.closest('[role="option"]');
      if (!opt) return;
      applyPeriod(opt.getAttribute('data-value'), true);
      setPeriodOpen(false);
    });
    document.addEventListener('click', function (e) {
      if (!periodMenu.hidden && !e.target.closest('.bhw-dash-period')) setPeriodOpen(false);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setPeriodOpen(false);
    });
  }

  lastChartsFp = stableFp(<?= json_encode($dashboardCharts) ?>);
  window.refreshBhwDashboard = refreshDashboard;

  setInterval(function () {
    if (document.hidden) return;
    if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
    refreshDashboard();
  }, REFRESH_MS);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) refreshDashboard();
  });
  document.addEventListener('medconnect:live-sync', function (ev) {
    var changed = (ev.detail && ev.detail.changed) || [];
    if (
      changed.indexOf('triage') !== -1 ||
      changed.indexOf('queue') !== -1 ||
      changed.indexOf('appointments') !== -1 ||
      changed.indexOf('consultations') !== -1 ||
      changed.indexOf('followups') !== -1 ||
      changed.indexOf('notifications') !== -1
    ) {
      refreshDashboard();
    }
  });
})();
<?php
$bhw_inline_script = ob_get_clean();
require __DIR__ . '/partials/layout_close.php';
?>
