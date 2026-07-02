<?php
session_start();
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
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Queue Monitoring';

$waiting = (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE status='scheduled' AND consult_date = CURDATE()")->fetchColumn();
$active = (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE status='in_consultation'")->fetchColumn();
$completed = (int)$pdo->query("SELECT COUNT(*) FROM consultations WHERE status='completed' AND consult_date = CURDATE()")->fetchColumn();

$queue_rows = $pdo->query("
    SELECT c.id, c.status, c.consult_time,
           CONCAT(p.first_name,' ',p.last_name) AS patient_name,
           CONCAT(pr.first_name,' ',pr.last_name) AS provider_name
    FROM consultations c
    JOIN users p ON p.id = c.patient_id
    LEFT JOIN users pr ON pr.id = c.provider_id
    WHERE c.consult_date = CURDATE() AND c.status IN ('scheduled','in_consultation','completed')
    ORDER BY FIELD(c.status,'in_consultation','scheduled','completed'), c.consult_time ASC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="margin-bottom:24px;">
  <h2 class="text-h2">Queue Monitoring</h2>
  <p class="text-muted">Today's waiting, active, and completed consultations.</p>
</div>

<div class="stats-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px;">
  <div class="mc-card"><div class="text-h1"><?= $waiting ?></div><div class="text-xs text-muted">Waiting Patients</div></div>
  <div class="mc-card"><div class="text-h1"><?= $active ?></div><div class="text-xs text-muted">Active Patients</div></div>
  <div class="mc-card"><div class="text-h1"><?= $completed ?></div><div class="text-xs text-muted">Completed Today</div></div>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;" id="queuePanel">
  <table class="mc-table">
    <thead><tr><th>Time</th><th>Patient</th><th>Provider</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($queue_rows as $q): ?>
      <tr>
        <td><?= date('g:i A', strtotime($q['consult_time'])) ?></td>
        <td><?= htmlspecialchars($q['patient_name']) ?></td>
        <td><?= htmlspecialchars($q['provider_name'] ?? 'Unassigned') ?></td>
        <td><span class="mc-badge"><?= htmlspecialchars($q['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>setInterval(function(){ location.reload(); }, 30000);</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
