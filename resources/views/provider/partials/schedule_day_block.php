<?php
/**
 * Provider schedule — daily availability editor.
 * Only the current calendar day is editable. Other weekdays are locked (view only).
 * At midnight, yesterday locks and the doctor sets a new schedule for the new day.
 *
 * @var string $day
 * @var bool $is_today
 * @var bool $day_active
 * @var array<int, array<string, mixed>> $day_sessions
 */
$duration_options = [15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '1 hour'];
$session_count = count($day_sessions);

$summaryParts = [];
if ($session_count === 0) {
    $summaryParts[] = $is_today ? 'No sessions yet — create today’s schedule' : 'No schedule saved';
} else {
    $summaryParts[] = $session_count . ' session' . ($session_count === 1 ? '' : 's');
    foreach (array_slice($day_sessions, 0, 2) as $preview) {
        $summaryParts[] = schedule_format_time((string) ($preview['start_time'] ?? ''))
            . '–'
            . schedule_format_time((string) ($preview['end_time'] ?? ''));
    }
    if ($session_count > 2) {
        $summaryParts[] = '+' . ($session_count - 2) . ' more';
    }
}
$summaryText = implode(' · ', $summaryParts);
?>
<article class="sched-day <?= $is_today ? 'sched-day--today' : 'sched-day--locked' ?> <?= $day_active ? 'sched-day--active' : '' ?>"
     data-day="<?= htmlspecialchars($day) ?>"
     data-editable="<?= $is_today ? '1' : '0' ?>"
     data-is-today="<?= $is_today ? '1' : '0' ?>">

  <header class="sched-day__head">
    <div class="sched-day__identity">
      <div class="sched-day__title-row">
        <h4 class="sched-day__name"><?= htmlspecialchars($day) ?></h4>
        <?php if ($is_today): ?>
        <span class="sched-day__badge sched-day__badge--today">Today · Editable</span>
        <?php else: ?>
        <span class="sched-day__badge sched-day__badge--locked">Locked</span>
        <?php endif; ?>
        <?php if ($day_active): ?>
        <span class="sched-day__badge sched-day__badge--on">Active</span>
        <?php elseif ($session_count > 0): ?>
        <span class="sched-day__badge sched-day__badge--off">Inactive</span>
        <?php elseif (!$is_today): ?>
        <span class="sched-day__badge sched-day__badge--empty">Unset</span>
        <?php endif; ?>
      </div>
      <p class="sched-day__summary" data-day-summary><?= htmlspecialchars($summaryText) ?></p>
    </div>
    <?php if ($is_today): ?>
    <button type="button" class="sched-day__toggle" data-toggle-day aria-expanded="true">
      <span data-toggle-label>Collapse</span>
      <svg class="sched-day__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <?php else: ?>
    <button type="button" class="sched-day__toggle" data-toggle-day aria-expanded="false">
      <span data-toggle-label>View</span>
      <svg class="sched-day__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <?php endif; ?>
  </header>

  <?php if ($is_today): ?>
  <div class="sched-day__body" data-day-body>
    <div class="sched-day__hint sched-day__hint--policy">
      Create or edit <strong>today&apos;s</strong> video-consult availability.
      At <strong>12:00 AM</strong> this day locks automatically — set a new schedule tomorrow.
    </div>

    <div class="sched-day-active-row">
      <label class="sched-day-active-label">
        <input type="checkbox" class="schedule-day-active" <?= $day_active ? 'checked' : '' ?>>
        <span>Accept patient bookings today</span>
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
      Save Today&apos;s Schedule
    </button>
  </div>

  <?php else: ?>
  <div class="sched-day__body" data-day-body hidden>
    <div class="sched-day__hint sched-day__hint--locked">
      Locked after midnight. Editable only when <?= htmlspecialchars($day) ?> is the current day.
      Create a fresh schedule each morning for video consultations.
    </div>
    <div class="sched-readonly-sessions">
      <?php if ($day_sessions === []): ?>
      <p class="sched-readonly-empty">No schedule was saved for this weekday.</p>
      <?php else: ?>
      <?php foreach ($day_sessions as $si => $session): ?>
      <div class="sched-readonly-session">
        <span class="sched-readonly-session__num">Session <?= $si + 1 ?></span>
        <span class="sched-readonly-session__time">
          <?= schedule_format_time((string) $session['start_time']) ?>
          &ndash;
          <?= schedule_format_time((string) $session['end_time']) ?>
        </span>
        <span class="sched-readonly-session__dur"><?= schedule_duration_label((int) ($session['slot_duration'] ?? 30)) ?> slots</span>
        <span class="sched-status-pill <?= (int) ($session['is_active'] ?? 0) === 1 ? 'sched-status-pill--active' : 'sched-status-pill--inactive' ?>">
          <?= (int) ($session['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
        </span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</article>
