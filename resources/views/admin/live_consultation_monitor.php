<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/auth_guard.php';
require_once BASE_PATH . '/app/includes/admin_queue_live.php';
require_once BASE_PATH . '/app/includes/portal_paths.php';
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Consultation Monitoring';
$liveApi = ASSET_BASE . '/app/api/admin/monitoring.php?type=live';
$cm_tab = strtolower(trim((string) ($_GET['tab'] ?? 'queue')));
if (!in_array($cm_tab, ['queue', 'live'], true)) {
    $cm_tab = 'queue';
}
$queue_embedded = true;
$cm_base = portal_views_base() . '/live_consultation_monitor.php';
$staffCssVer = (int) @filemtime(ASSETS_PATH . '/css/admin-staff-applications.css');
$cmJsVer = (int) @filemtime(ASSETS_PATH . '/js/admin-consultation-monitor.js');
$adminQueueLiveVer = (int) @filemtime(ASSETS_PATH . '/js/admin-queue-live.js');

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-staff-applications.css?v=<?= $staffCssVer ?>">

<article class="cm-monitor-page" id="cmMonitor">
  <div class="header-row" style="margin-bottom:20px;">
    <h2 class="text-h2">Consultation Monitoring</h2>
    <p class="text-muted">Monitor waiting patients and active consultations.</p>
  </div>

  <nav class="staff-mgmt-tabs" aria-label="Consultation monitoring views">
    <a href="<?= htmlspecialchars($cm_base . '?tab=queue') ?>"
       class="staff-mgmt-tabs__item<?= $cm_tab === 'queue' ? ' is-active' : '' ?>"
       data-cm-tab="queue"
       <?= $cm_tab === 'queue' ? 'aria-current="page"' : '' ?>>Queue</a>
    <a href="<?= htmlspecialchars($cm_base . '?tab=live') ?>"
       class="staff-mgmt-tabs__item<?= $cm_tab === 'live' ? ' is-active' : '' ?>"
       data-cm-tab="live"
       <?= $cm_tab === 'live' ? 'aria-current="page"' : '' ?>>Live Consultations</a>
  </nav>

  <section data-cm-panel="queue"<?= $cm_tab === 'queue' ? '' : ' hidden' ?>>
    <?php require __DIR__ . '/partials/queue_monitoring_panel.php'; ?>
  </section>

  <section data-cm-panel="live"<?= $cm_tab === 'live' ? '' : ' hidden' ?>>
    <?php require __DIR__ . '/partials/live_consultation_panel.php'; ?>
  </section>
</article>

<script src="<?= ASSET_BASE ?>/assets/js/admin-queue-live.js?v=<?= $adminQueueLiveVer ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-consultation-monitor.js?v=<?= $cmJsVer ?>"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
