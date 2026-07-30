<?php
/**
 * Provider schedule — multi-session day editor (partial).
 * All weekdays are editable (weekly template). Slots for today regenerate when today is saved.
 *
 * @var string $day
 * @var bool $is_today
 * @var bool $day_active
 * @var array<int, array<string, mixed>> $day_sessions
 */
$duration_options = [15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '1 hour'];
$session_count = count($day_sessions);
?>
<div class="sched-day-block <?= $is_today ? 'sched-day-block--today' : '' ?>"
     data-day="<?= htmlspecialchars($day) ?>"
     data-editable="1"
     data-is-today="<?= $is_today ? '1' : '0' ?>">

  <div class="sched-day-block__head">
    <div>
      <h4 class="sched-day-block__title">
        <?= htmlspecialchars($day) ?>
        <?php if ($is_today): ?>
        <span class="sched-today-badge">Today</span>
        <?php endif; ?>
        <?php if ($day_active): ?>
        <span class="sched-status-pill sched-status-pill--active">Active</span>
        <?php elseif ($session_count > 0): ?>
        <span class="sched-status-pill sched-status-pill--inactive">Inactive</span>
        <?php endif; ?>
      </h4>
      <p class="sched-day-block__hint">
        <?php if ($is_today): ?>
        Editing today&apos;s hours opens slots patients can book now.
        <?php else: ?>
        Set recurring hours for every <?= htmlspecialchars($day) ?>. Applies when that day arrives.
        <?php endif; ?>
      </p>
    </div>
    <button type="button" class="sched-day-toggle" data-toggle-day aria-expanded="true">
      <span data-toggle-label>Collapse</span>
    </button>
  </div>

  <div class="sched-day-block__body" data-day-body>
    <div class="sched-day-active-row">
      <label class="sched-day-active-label">
        <input type="checkbox" class="schedule-day-active" <?= $day_active ? 'checked' : '' ?>>
        <span><?= $is_today ? 'Accept patient bookings today' : 'Accept bookings on ' . htmlspecialchars($day) . 's' ?></span>
      </label>
    </div>

    <div class="sched-sessions-list" data-sessions-list>
      <?php
      $sessions = $day_sessions;
      if ($sessions === []) {
          $sessions = [[
              'id' => null,
              'start_time' => '09:00:00',
              'end_time' => '17:00:00',
              'slot_duration' => 30,
          ]];
      }
      foreach ($sessions as $si => $session):
          $sid = $session['id'] ?? '';
          $duration = (int) ($session['slot_duration'] ?? 30);
      ?>
      <div class="sched-session-card" data-session-card data-session-id="<?= htmlspecialchars((string) $sid) ?>">
        <div class="sched-session-card__head">
          <span class="sched-session-card__label">Session <span data-session-num><?= $si + 1 ?></span></span>
          <button type="button" class="sched-session-remove" data-remove-session title="Remove session" aria-label="Remove session">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Remove
          </button>
        </div>
        <div class="sched-session-card__grid">
          <div class="sched-session-field">
            <label>Start time</label>
            <input type="time" class="sched-field schedule-start" value="<?= date('H:i', strtotime((string) $session['start_time'])) ?>" required>
          </div>
          <div class="sched-session-field">
            <label>End time</label>
            <input type="time" class="sched-field schedule-end" value="<?= date('H:i', strtotime((string) $session['end_time'])) ?>" required>
          </div>
          <div class="sched-session-field">
            <label>Slot length</label>
            <select class="sched-field schedule-duration">
              <?php foreach ($duration_options as $mins => $label): ?>
              <option value="<?= $mins ?>" <?= $duration === $mins ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <button type="button" class="sched-add-session" data-add-session>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Session
    </button>

    <div class="sched-validation" data-sched-validation hidden role="alert"></div>

    <button type="button" class="mc-btn mc-btn--primary sched-save-day-btn schedule-save-btn">
      Save <?= htmlspecialchars($day) ?> Schedule
    </button>
  </div>
</div>
