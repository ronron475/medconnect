<?php
$active_page = 'schedule';
$page_title  = 'Schedule & Availability';
$page_styles = ['provider-schedule.css'];
require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require_once BASE_PATH . '/app/includes/appointment_slots.php';
require __DIR__.'/partials/layout_open.php';

$provider_id = (int) $_SESSION['user_id'];
appointment_slots_sync_today($pdo, $provider_id);

function schedule_day_active(array $row): bool
{
    return (int) ($row['is_active'] ?? 0) === 1;
}

function schedule_format_time(string $time): string
{
    $ts = strtotime($time);

    return $ts ? date('g:i A', $ts) : '—';
}

function schedule_duration_label(int $minutes): string
{
    return $minutes === 60 ? '1 hour' : $minutes . ' min';
}

// Latest saved row per weekday.
$stmt = $pdo->prepare("
    SELECT ps.day_of_week, ps.start_time, ps.end_time, ps.slot_duration, ps.is_active
    FROM provider_schedules ps
    INNER JOIN (
        SELECT day_of_week, MAX(id) AS max_id
        FROM provider_schedules
        WHERE provider_id = ?
        GROUP BY day_of_week
    ) latest ON latest.max_id = ps.id
    ORDER BY FIELD(ps.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')
");
$stmt->execute([$provider_id]);
$saved_schedules = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $saved_schedules[$row['day_of_week']] = $row;
}

$days_order = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$today_name = date('l');
$today_is_active = schedule_day_active($saved_schedules[$today_name] ?? ['is_active' => 0]);

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
?>

<div class="sched-page-header">
  <div>
    <h2 class="text-h2">Weekly Availability</h2>
    <p>
      Configure <strong><?= htmlspecialchars($today_name) ?></strong> for today (<?= date('M j, Y') ?>).
      Other weekdays are read-only until their calendar day.
    </p>
  </div>
  <div class="sched-summary">
    <span class="sched-summary-chip sched-summary-chip--today">
      Today: <?= $today_is_active ? 'Accepting bookings' : 'Not active' ?>
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
      <div class="mc-card-body mt-2">
        <div class="sched-table-wrap">
          <table class="sched-table">
            <thead>
              <tr>
                <th>Day</th>
                <th>Status</th>
                <th>Start</th>
                <th>End</th>
                <th>Slot length</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($days_order as $day):
                $s = $saved_schedules[$day] ?? [
                    'is_active' => 0,
                    'start_time' => '09:00:00',
                    'end_time' => '17:00:00',
                    'slot_duration' => 30,
                ];
                $is_today = ($day === $today_name);
                $is_active = schedule_day_active($s);
                $duration = (int) ($s['slot_duration'] ?? 30);
              ?>
              <tr class="schedule-row <?= $is_today ? 'sched-row--today' : 'sched-row--locked' ?>"
                  data-day="<?= htmlspecialchars($day) ?>"
                  data-editable="<?= $is_today ? '1' : '0' ?>">
                <td>
                  <span class="sched-day-name"><?= htmlspecialchars($day) ?></span>
                  <?php if ($is_today): ?>
                  <span class="sched-today-badge">Today</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($is_today): ?>
                  <label class="sched-toggle" title="<?= $is_active ? 'Active — patients can book' : 'Inactive — no bookings' ?>">
                    <input type="checkbox" class="schedule-active" <?= $is_active ? 'checked' : '' ?> aria-label="Toggle availability for <?= htmlspecialchars($day) ?>">
                    <span class="sched-toggle__track">
                      <span class="sched-toggle__thumb"></span>
                    </span>
                  </label>
                  <?php else: ?>
                  <span class="sched-status-pill <?= $is_active ? 'sched-status-pill--active' : 'sched-status-pill--inactive' ?>">
                    <?= $is_active ? 'Active' : 'Inactive' ?>
                  </span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($is_today): ?>
                  <input type="time" class="sched-field schedule-start" value="<?= date('H:i', strtotime((string) $s['start_time'])) ?>">
                  <?php else: ?>
                  <span class="sched-readonly"><?= schedule_format_time((string) $s['start_time']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($is_today): ?>
                  <input type="time" class="sched-field schedule-end" value="<?= date('H:i', strtotime((string) $s['end_time'])) ?>">
                  <?php else: ?>
                  <span class="sched-readonly"><?= schedule_format_time((string) $s['end_time']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($is_today): ?>
                  <select class="sched-field schedule-duration">
                    <?php foreach ([15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '1 hour'] as $mins => $label): ?>
                    <option value="<?= $mins ?>" <?= $duration === $mins ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php else: ?>
                  <span class="sched-readonly"><?= schedule_duration_label($duration) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($is_today): ?>
                  <button type="button" class="mc-btn mc-btn--primary sched-save-btn schedule-save-btn">Save</button>
                  <?php else: ?>
                  <span class="sched-locked-label">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    Locked
                  </span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
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
          Patients can book open slots that haven&apos;t passed yet.
        </div>
        <?php else: ?>
        <div class="sched-status-banner sched-status-banner--warn">
          <strong><?= htmlspecialchars($today_name) ?> is inactive.</strong>
          Turn today on and click <strong>Save</strong> to generate bookable slots.
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
          Today is active but no slots were generated yet.<br>
          Set your hours and click <strong>Save</strong> to create slots.
        </p>
        <?php else: ?>
        <p class="sched-preview-empty">
          No slots for today.<br>
          Enable <strong><?= htmlspecialchars($today_name) ?></strong> and save to open booking.
        </p>
        <?php endif; ?>
      </div>
    </div>

    <div class="mc-card mt-4 sched-note-card">
      <h4 class="text-h3" style="color:#069396;margin-bottom:8px;">How it works</h4>
      <p>
        Only <strong><?= htmlspecialchars($today_name) ?></strong> can be edited on this page.
        Saving regenerates today&apos;s <strong>available</strong> slots.
        Already <strong>booked</strong> appointments are never removed.
      </p>
    </div>
  </div>
</div>

<script>
(function () {
  const SCHEDULE_TODAY = <?= json_encode($today_name, JSON_THROW_ON_ERROR) ?>;
  const SCHEDULE_API = <?= json_encode(ASSET_BASE . '/app/api/provider/save_schedule.php', JSON_THROW_ON_ERROR) ?>;
  const LOGIN_URL = <?= json_encode(ASSET_BASE . '/index.php', JSON_THROW_ON_ERROR) ?>;

  document.querySelectorAll('.schedule-save-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const row = btn.closest('.schedule-row');
      if (!row) return;

      const day = row.dataset.day || '';
      if (row.dataset.editable !== '1' || day !== SCHEDULE_TODAY) {
        alert('You can only update today\'s schedule (' + SCHEDULE_TODAY + ').');
        return;
      }

      const startTime = row.querySelector('.schedule-start')?.value || '';
      const endTime = row.querySelector('.schedule-end')?.value || '';
      const duration = row.querySelector('.schedule-duration')?.value || '30';
      const isActive = row.querySelector('.schedule-active')?.checked ? '1' : '0';

      if (!startTime || !endTime) {
        alert('Please set start and end times.');
        return;
      }
      if (startTime >= endTime) {
        alert('End time must be later than start time.');
        return;
      }

      const originalText = btn.textContent;
      btn.disabled = true;
      btn.classList.add('is-saving');
      btn.textContent = 'Saving…';

      const fd = new FormData();
      fd.append('day', day);
      fd.append('start_time', startTime);
      fd.append('end_time', endTime);
      fd.append('duration', duration);
      if (isActive === '1') {
        fd.append('is_active', '1');
      }

      try {
        const res = await fetch(SCHEDULE_API, {
          method: 'POST',
          body: fd,
          credentials: 'include',
          cache: 'no-store',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        const raw = await res.text();
        let data;
        try {
          data = JSON.parse(raw);
        } catch {
          throw new Error(raw || 'Invalid server response.');
        }

        if (data.success) {
          btn.classList.remove('is-saving');
          btn.classList.add('is-success');
          btn.textContent = 'Saved';
          if (window.mcToast) {
            window.mcToast(data.message || 'Schedule saved. Slots updated for today.');
          }
          setTimeout(() => window.location.reload(), 650);
          return;
        }

        if (data.message === 'Unauthorized.') {
          alert('Your session expired. Please log in again.');
          window.location.href = LOGIN_URL;
          return;
        }

        alert(data.message || 'Could not save schedule.');
      } catch (err) {
        alert(err.message || 'Error saving schedule.');
      }

      btn.disabled = false;
      btn.classList.remove('is-saving');
      btn.textContent = originalText;
    });
  });
})();
</script>

<?php require __DIR__.'/partials/layout_close.php'; ?>
