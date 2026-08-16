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
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Queue Monitoring';

$queueLive = admin_queue_live_payload($pdo);
$waiting = (int) ($queueLive['waiting'] ?? 0);
$active = (int) ($queueLive['active'] ?? 0);
$completed = (int) ($queueLive['completed'] ?? 0);
$queue_rows = $queueLive['rows'] ?? [];

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="margin-bottom:24px;">
  <h2 class="text-h2">Queue Monitoring</h2>
  <p class="text-muted">Today's waiting, active, and completed consultations.</p>
</div>

<div id="adminQueueLive" data-fingerprint="<?= htmlspecialchars((string) ($queueLive['fingerprint'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
  <div class="mc-card"><div class="text-h1" data-queue-metric="waiting"><?= $waiting ?></div><div class="text-xs text-muted">Waiting Patients</div></div>
  <div class="mc-card"><div class="text-h1" data-queue-metric="active"><?= $active ?></div><div class="text-xs text-muted">Active Patients</div></div>
  <div class="mc-card"><div class="text-h1" data-queue-metric="completed"><?= $completed ?></div><div class="text-xs text-muted">Completed Today</div></div>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;" id="queuePanel">
  <table class="mc-table">
    <thead><tr><th>Time</th><th>Patient</th><th>Provider</th><th>Status</th></tr></thead>
    <tbody data-queue-body>
      <?php foreach ($queue_rows as $q): ?>
      <tr>
        <td><?= htmlspecialchars((string) ($q['time_label'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($q['patient_name'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($q['provider_name'] ?? 'Unassigned')) ?></td>
        <td><span class="mc-badge"><?= htmlspecialchars((string) ($q['status'] ?? '')) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</div>
<?php $adminQueueLiveVer = (int) @filemtime(ASSETS_PATH . '/js/admin-queue-live.js'); ?>
<script src="<?= ASSET_BASE ?>/assets/js/admin-queue-live.js?v=<?= $adminQueueLiveVer ?>"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
