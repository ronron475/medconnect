<?php
$active_page = 'appointments';
$page_title  = 'Appointments';
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require __DIR__.'/partials/layout_open.php';
?>

<!-- FILTER TABS -->
<div class="pd-tabs" style="margin-bottom:20px">
  <button class="pd-tab active">All</button>
  <button class="pd-tab">Today</button>
  <button class="pd-tab">This Week</button>
  <button class="pd-tab">Pending</button>
  <button class="pd-tab">Cancelled</button>
</div>

<div class="pd-two-col">
  <!-- UPCOMING APPOINTMENTS -->
  <div class="pd-panel">
    <div class="pd-panel-header">
      <div class="pd-panel-title"><?= icon('calendar') ?> Upcoming Appointments</div>
      <button class="pd-panel-action">+ New</button>
    </div>
    <div style="overflow-x:auto">
      <table class="pd-table">
        <thead><tr><th>Patient</th><th>Type</th><th>Date / Time</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($appointments as $a):
          $ac=match($a['status']){'confirmed'=>'confirmed','urgent'=>'urgent',default=>'waiting'};
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <div class="pd-appt-avatar"><?= htmlspecialchars($a['initials']) ?></div>
              <span class="pd-patient-name"><?= htmlspecialchars($a['name']) ?></span>
            </div>
          </td>
          <td style="font-size:12.5px;color:var(--text-muted)"><?= htmlspecialchars($a['type']) ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= $a['time'] ?></td>
          <td><span class="pd-badge pd-badge-<?= $ac ?>"><?= ucfirst($a['status']) ?></span></td>
          <td>
            <div style="display:flex;gap:5px">
              <button class="pd-action-btn teal"><?= icon_sm('check') ?> Accept</button>
              <button class="pd-action-btn"><?= icon_sm('edit') ?> Reschedule</button>
              <button class="pd-action-btn"><?= icon_sm('x') ?></button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- SCHEDULE SIDEBAR -->
  <div style="display:flex;flex-direction:column;gap:18px">
    <div class="pd-panel">
      <div class="pd-panel-header"><div class="pd-panel-title"><?= icon('clock') ?> Today's Schedule</div></div>
      <div class="pd-panel-body">
        <?php foreach($schedule as $s):
          $sc2=match($s['status']){'confirmed'=>'confirmed','urgent'=>'urgent',default=>'waiting'};
        ?>
        <div class="pd-schedule-item">
          <div class="pd-sched-time"><?= $s['time'] ?></div>
          <div class="pd-sched-info">
            <div class="pd-sched-name"><?= htmlspecialchars($s['name']) ?></div>
            <div class="pd-sched-type"><?= htmlspecialchars($s['type']) ?></div>
          </div>
          <span class="pd-badge pd-badge-<?= $sc2 ?>"><?= ucfirst($s['status']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="pd-panel">
      <div class="pd-panel-header"><div class="pd-panel-title"><?= icon('alert') ?> Reschedule Requests</div></div>
      <div class="pd-panel-body">
        <?php foreach(array_filter($appointments,fn($a)=>$a['type']==='Reschedule Request') as $a): ?>
        <div class="pd-appt-item">
          <div class="pd-appt-avatar"><?= htmlspecialchars($a['initials']) ?></div>
          <div class="pd-appt-info">
            <div class="pd-appt-name"><?= htmlspecialchars($a['name']) ?></div>
            <div class="pd-appt-type"><?= $a['time'] ?></div>
          </div>
          <div class="pd-appt-actions">
            <button class="pd-action-btn teal"><?= icon_sm('check') ?></button>
            <button class="pd-action-btn"><?= icon_sm('x') ?></button>
          </div>
        </div>
        <?php endforeach; ?>
        <p style="font-size:12px;color:var(--text-muted);margin-top:8px">1 pending reschedule request</p>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__.'/partials/layout_close.php'; ?>
