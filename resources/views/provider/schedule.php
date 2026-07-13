<?php
$active_page = 'schedule';
$page_title  = 'Schedule & Availability';
$page_styles = ['provider-schedule.css'];
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require_once BASE_PATH . '/app/includes/appointment_slots.php';
require_once BASE_PATH . '/app/includes/provider_schedule_sessions.php';
require __DIR__.'/partials/layout_open.php';

$provider_id = (int) $_SESSION['user_id'];
provider_schedule_ensure_schema($pdo);
appointment_slots_sync_today($pdo, $provider_id);

function schedule_format_time(string $time): string
{
    $ts = strtotime($time);

    return $ts ? date('g:i A', $ts) : '—';
}

function schedule_duration_label(int $minutes): string
{
    return $minutes === 60 ? '1 hour' : $minutes . ' min';
}

$schedules_by_day = provider_schedule_load_grouped($pdo, $provider_id);
$days_order = provider_schedule_valid_days();
$today_name = date('l');
$today_sessions = $schedules_by_day[$today_name] ?? [];
$today_is_active = provider_schedule_day_is_active($today_sessions);

$s_stmt = $pdo->prepare("
    SELECT s.start_time, s.end_time, s.status,
           COALESCE(CONCAT(u.first_name, ' ', u.last_name), '') AS patient_name
    FROM appointment_slots s
    LEFT JOIN users u ON u.id = s.patient_id
    WHERE s.provider_id = ? AND s.slot_date = CURDATE()
    ORDER BY s.start_time ASC
");
$s_stmt->execute([$provider_id]);
$today_slots = $s_stmt->fetchAll();

$slot_counts = ['available' => 0, 'booked' => 0, 'passed' => 0];
foreach ($today_slots as $sl) {
    if (($sl['status'] ?? '') === 'booked') {
        $slot_counts['booked']++;
        continue;
    }
    if (substr((string) ($sl['start_time'] ?? ''), 0, 8) <= date('H:i:s')) {
        $slot_counts['passed']++;
    } else {
        $slot_counts['available']++;
    }
}

$session_count_today = count($today_sessions);
?>

<div class="sched-page-header">
  <div>
    <h2 class="text-h2">Weekly Availability</h2>
    <p>
      Define <strong>multiple clinic sessions</strong> for <strong><?= htmlspecialchars($today_name) ?></strong>
      (<?= date('M j, Y') ?>). Morning, afternoon, and evening hours are supported.
      Other weekdays are read-only until their calendar day.
    </p>
  </div>
  <div class="sched-summary">
    <span class="sched-summary-chip sched-summary-chip--today">
      Today: <?= $today_is_active ? 'Accepting bookings' : 'Not active' ?>
    </span>
    <span class="sched-summary-chip sched-summary-chip--sessions">
      <?= $session_count_today ?> session<?= $session_count_today === 1 ? '' : 's' ?> today
    </span>
    <span class="sched-summary-chip sched-summary-chip--slots">
      <?= count($today_slots) ?> slot<?= count($today_slots) === 1 ? '' : 's' ?> generated
    </span>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="mc-card">
      <div class="mc-card-header">
        <h3 class="text-h3"><?= icon('calendar') ?> Day Configuration</h3>
      </div>
      <div class="mc-card-body mt-2 sched-days-stack">
        <?php foreach ($days_order as $day):
          $day_sessions = $schedules_by_day[$day] ?? [];
          $is_today = ($day === $today_name);
          $day_active = provider_schedule_day_is_active($day_sessions);
          include __DIR__ . '/partials/schedule_day_block.php';
        endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="mc-card sched-preview-card">
      <div class="mc-card-header">
        <h3 class="text-h3"><?= icon('clock') ?> Today&apos;s Slots</h3>
        <span class="mc-badge"><?= date('M j, Y') ?></span>
      </div>
      <div class="mc-card-body mt-2">
        <?php if ($today_is_active): ?>
        <div class="sched-status-banner sched-status-banner--ok">
          <strong><?= htmlspecialchars($today_name) ?> is active.</strong>
          Slots from all sessions appear below in chronological order.
        </div>
        <?php else: ?>
        <div class="sched-status-banner sched-status-banner--warn">
          <strong><?= htmlspecialchars($today_name) ?> is inactive.</strong>
          Add sessions, enable bookings, and click <strong>Save</strong>.
        </div>
        <?php endif; ?>

        <?php if (!empty($today_slots)): ?>
        <div class="sched-slot-stats">
          <div class="sched-slot-stat sched-slot-stat--open">
            <strong><?= $slot_counts['available'] ?></strong>
            <span>Open</span>
          </div>
          <div class="sched-slot-stat sched-slot-stat--booked">
            <strong><?= $slot_counts['booked'] ?></strong>
            <span>Booked</span>
          </div>
          <div class="sched-slot-stat sched-slot-stat--past">
            <strong><?= $slot_counts['passed'] ?></strong>
            <span>Passed</span>
          </div>
        </div>
        <h4 class="sched-preview-title"><?= htmlspecialchars($today_name) ?> timeline</h4>
        <div class="sched-slot-grid-wrap">
          <?php
          $slot_list = $today_slots;
          $slot_preview_date = date('Y-m-d');
          include __DIR__ . '/partials/schedule_slot_grid.php';
          ?>
        </div>
        <?php elseif ($today_is_active): ?>
        <p class="sched-preview-empty">
          Sessions are active but no slots were generated yet.<br>
          Configure your sessions and click <strong>Save <?= htmlspecialchars($today_name) ?> Schedule</strong>.
        </p>
        <?php else: ?>
        <p class="sched-preview-empty">
          No slots for today.<br>
          Add sessions, enable bookings, and save.
        </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="mc-card mt-4 sched-note-card">
      <h4 class="text-h3" style="color:#069396;margin-bottom:8px;">How it works</h4>
      <p>
        Use <strong>+ Add Session</strong> for split clinic hours (e.g. 5:00–8:00 AM, 1:00–3:00 PM).
        Each session can have its own slot length. Saving regenerates today&apos;s <strong>available</strong> slots;
        <strong>booked</strong> appointments are never removed.
      </p>
    </div>
  </div>
</div>

<script>
  window.SCHEDULE_CONFIG = <?= json_encode([
      'today'    => $today_name,
      'api'      => ASSET_BASE . '/app/api/provider/save_schedule.php',
      'loginUrl' => ASSET_BASE . '/index.php',
  ], JSON_THROW_ON_ERROR) ?>;
</script>
<script src="<?= ASSET_BASE ?>/assets/js/provider-schedule.js?v=20260703j"></script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
