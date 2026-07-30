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
    <h2 class="text-h2">Daily Availability</h2>
    <p>
      Set your <strong>video consultation</strong> hours for <strong>today only</strong>
      (<?= htmlspecialchars($today_name) ?>, <?= date('M j, Y') ?>).
      At <strong>12:00 AM</strong> today&apos;s schedule locks — create a new one tomorrow.
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

<div class="sched-policy-banner" role="note">
  <strong>Daily schedule policy:</strong>
  Doctors create and edit availability for the current day only.
  After midnight the day is locked and cannot be edited.
</div>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="mc-card">
      <div class="mc-card-header">
        <h3 class="text-h3"><?= icon('calendar') ?> Today&apos;s Schedule</h3>
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
        Build <strong>today&apos;s</strong> clinic sessions (e.g. morning and afternoon), choose slot length, then save.
        Patients book open slots for <strong>today</strong> only.
        At <strong>12:00 AM</strong> this day locks — tomorrow you create a new schedule.
        Other weekdays are view-only until that calendar day arrives.
      </p>
    </div>
  </div>
</div>

<div id="schedConfirmModal" class="sched-confirm" hidden aria-hidden="true">
  <div class="sched-confirm__backdrop" data-sched-confirm-cancel></div>
  <div class="sched-confirm__dialog" role="dialog" aria-modal="true" aria-labelledby="schedConfirmTitle">
    <h3 id="schedConfirmTitle" class="sched-confirm__title">Save schedule?</h3>
    <p id="schedConfirmMessage" class="sched-confirm__message">
      Are you sure you want to save today&apos;s availability?
    </p>
    <div class="sched-confirm__actions">
      <button type="button" class="mc-btn mc-btn--outline" data-sched-confirm-cancel>No</button>
      <button type="button" class="mc-btn mc-btn--primary" data-sched-confirm-yes>Yes</button>
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
<?php $schedJsVer = (int) @filemtime(ASSETS_PATH . '/js/provider-schedule.js'); ?>
<script src="<?= ASSET_BASE ?>/assets/js/provider-schedule.js?v=<?= $schedJsVer ?>"></script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
