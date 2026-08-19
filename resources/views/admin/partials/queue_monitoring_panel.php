<?php
/**
 * Shared queue monitoring panel — used by Consultation Monitoring and Doctor hub queue tab.
 */
declare(strict_types=1);

if (!isset($queueLive) || !is_array($queueLive)) {
    require_once BASE_PATH . '/app/includes/admin_queue_live.php';
    $queueLive = admin_queue_live_payload($pdo);
}

$waiting = (int) ($queueLive['waiting'] ?? 0);
$active = (int) ($queueLive['active'] ?? 0);
$completed = (int) ($queueLive['completed'] ?? 0);
$queue_rows = $queueLive['rows'] ?? [];
$queue_embedded = !empty($queue_embedded);
$queue_colspan = 7;
?>

<?php if (!$queue_embedded): ?>
<div class="header-row" style="margin-bottom:24px;">
  <h2 class="text-h2">Queue</h2>
  <p class="text-muted">Today's waiting, active, and completed consultations.</p>
</div>
<?php endif; ?>

<div id="adminQueueLive" data-fingerprint="<?= htmlspecialchars((string) ($queueLive['fingerprint'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<div class="stats-grid" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px;">
  <div class="mc-card"><div class="text-h1" data-queue-metric="waiting"><?= $waiting ?></div><div class="text-xs text-muted">Waiting Patients</div></div>
  <div class="mc-card"><div class="text-h1" data-queue-metric="active"><?= $active ?></div><div class="text-xs text-muted">Active Patients</div></div>
  <div class="mc-card"><div class="text-h1" data-queue-metric="completed"><?= $completed ?></div><div class="text-xs text-muted">Completed Today</div></div>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;" id="queuePanel">
  <table class="mc-table admin-stack-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Patient</th>
        <th>Priority</th>
        <th>Waiting</th>
        <th>Time</th>
        <th>Provider</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody data-queue-body>
      <?php if (!$queue_rows): ?>
      <tr><td colspan="<?= $queue_colspan ?>"><div class="mc-table-empty"><p>No consultations for today.</p></div></td></tr>
      <?php else: ?>
      <?php foreach ($queue_rows as $q): ?>
      <tr>
        <td data-label="Queue position"><?= htmlspecialchars((string) ($q['queue_position'] ?? '—')) ?></td>
        <td data-label="Patient"><?= htmlspecialchars((string) ($q['patient_name'] ?? '')) ?></td>
        <td data-label="Priority"><?= htmlspecialchars((string) ($q['priority_label'] ?? '—')) ?></td>
        <td data-label="Waiting time"><?= htmlspecialchars((string) ($q['waiting_label'] ?? '—')) ?></td>
        <td data-label="Time"><?= htmlspecialchars((string) ($q['time_label'] ?? '')) ?></td>
        <td data-label="Assigned provider"><?= htmlspecialchars((string) ($q['provider_name'] ?? 'Unassigned')) ?></td>
        <td data-label="Status"><span class="mc-badge"><?= htmlspecialchars((string) ($q['status'] ?? '')) ?></span></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</div>
