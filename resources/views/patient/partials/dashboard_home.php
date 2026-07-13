<?php
/**
 * Patient dashboard home — redesigned layout.
 */
$pt_first = htmlspecialchars(trim($pt['first_name'] ?? 'Patient'));
$patient_id_label = htmlspecialchars($pt['patient_number'] ?? ('MC-' . str_pad((string) $uid, 6, '0', STR_PAD_LEFT)));

$upcoming_list = array_values($upcoming_consults);

$pt_dash_provider_initials = static function (?string $name): string {
    $name = trim((string) $name);
    if ($name === '') {
        return 'DR';
    }
    $parts = preg_split('/\s+/', $name);
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = mb_substr($parts[count($parts) - 1] ?? '', 0, 1);

    return strtoupper($first . $last);
};

$pt_reg_status = strtolower(trim((string) ($pt['reg_status'] ?? 'pending')));
$pt_is_verified = in_array($pt_reg_status, ['verified', 'active', 'approved'], true);
$pt_status_label = $pt_is_verified ? 'Verified Patient' : 'Account Pending';

$dash_live_session = null;
$dash_live_join = null;
foreach ($upcoming_list as $c) {
    $access = consultation_patient_join_access($c);
    if ($access['allowed'] || $access['mode'] === 'waiting') {
        $dash_live_session = $c;
        $dash_live_join = $access;
        break;
    }
}
?>
<div id="view-dashboard" class="patient-page pdash-page">

  <section class="pdash-hero" aria-label="Welcome">
    <div class="pdash-hero__content">
      <p class="pdash-hero__eyebrow">Patient Care Portal</p>
      <h1 class="pdash-hero__title"><?= htmlspecialchars($dash_greeting_time) ?>, <?= $pt_first ?></h1>
      <p class="pdash-hero__sub">Manage appointments, visit history, prescriptions, and health records in one secure place.</p>
      <div class="pdash-hero__badges">
        <span class="pdash-badge <?= $pt_is_verified ? 'pdash-badge--verified' : 'pdash-badge--pending' ?>"><?= htmlspecialchars($pt_status_label) ?></span>
        <span class="pdash-badge pdash-badge--id">Patient ID: <strong><?= $patient_id_label ?></strong></span>
      </div>
    </div>
    <div class="pdash-hero__actions">
      <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pdash-btn pdash-btn--primary">Book Consultation</a>
      <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="pdash-btn pdash-btn--outline">My Sessions</a>
    </div>
  </section>

  <?php if ($dash_live_session && $dash_live_join): ?>
  <div class="pdash-live" role="status" aria-live="polite">
    <span class="pdash-live__pulse" aria-hidden="true"></span>
    <div class="pdash-live__text">
      <?php if ($dash_live_join['allowed']): ?>
      <strong>Your consultation is ready to join</strong>
      <span>Dr. <?= htmlspecialchars($dash_live_session['provider_name'] ?? 'your provider') ?> has opened the video room.</span>
      <?php else: ?>
      <strong>Waiting for your provider</strong>
      <span>Your session with Dr. <?= htmlspecialchars($dash_live_session['provider_name'] ?? 'your provider') ?> will open when they start the call.</span>
      <?php endif; ?>
    </div>
    <?php if ($dash_live_join['allowed'] && !empty($dash_live_session['room_token'])): ?>
    <a href="<?= htmlspecialchars($video_base) ?>?token=<?= urlencode($dash_live_session['room_token']) ?>" class="pdash-btn pdash-btn--join pdash-btn--sm">Join Call</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="pdash-metrics" aria-label="Health summary">
    <div class="pdash-metric <?= $dash_today_appts > 0 ? 'pdash-metric--accent' : '' ?>">
      <span class="pdash-metric__value"><?= (int) $dash_today_appts ?></span>
      <span class="pdash-metric__label">Today's Appointments</span>
    </div>
    <div class="pdash-metric <?= $dash_upcoming_count > 0 ? 'pdash-metric--warn' : '' ?>">
      <span class="pdash-metric__value"><?= (int) $dash_upcoming_count ?></span>
      <span class="pdash-metric__label">Upcoming</span>
    </div>
    <div class="pdash-metric">
      <span class="pdash-metric__value"><?= (int) $dash_total_sessions ?></span>
      <span class="pdash-metric__label">Total Visits</span>
    </div>
    <div class="pdash-metric">
      <span class="pdash-metric__value"><?= (int) $dash_completed_month ?></span>
      <span class="pdash-metric__label">Completed This Month</span>
    </div>
  </div>

  <nav class="pdash-quick" aria-label="Quick links">
    <a href="<?= ASSET_BASE ?>/views/patient/health_summary.php" class="pdash-quick__link">
      <span class="pdash-quick__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
      </span>
      Health Summary
    </a>
    <a href="<?= ASSET_BASE ?>/views/patient/my_health.php" class="pdash-quick__link">
      <span class="pdash-quick__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      </span>
      My Health
    </a>
    <a href="<?= ASSET_BASE ?>/views/patient/messages.php" class="pdash-quick__link">
      <span class="pdash-quick__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </span>
      Messages
    </a>
    <a href="<?= ASSET_BASE ?>/views/patient/settings.php" class="pdash-quick__link">
      <span class="pdash-quick__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </span>
      Settings
    </a>
  </nav>

  <div class="pdash-grid">
    <div class="pdash-main">

      <section class="pdash-card">
        <div class="pdash-card__head">
          <h2 class="pdash-card__title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Consultation Activity
          </h2>
          <span class="pdash-card__badge"><?= (int) $week_total ?> this week</span>
        </div>
        <p class="pdash-card__sub">Your consultations over the last 7 days</p>
        <div class="pdash-chart" role="img" aria-label="Weekly consultation bar chart">
          <?php foreach ($week_chart as $bar):
            $pct = ($bar['count'] / $chart_max) * 100;
            $height = max(6, round($pct));
          ?>
          <div class="pdash-chart-col <?= $bar['is_today'] ? 'is-today' : '' ?>">
            <div class="pdash-chart-bar-wrap">
              <div class="pdash-chart-bar" style="height: <?= $height ?>%;" title="<?= (int) $bar['count'] ?> on <?= htmlspecialchars($bar['date']) ?>"></div>
            </div>
            <span class="pdash-chart-label"><?= htmlspecialchars($bar['label']) ?></span>
            <span class="pdash-chart-val"><?= (int) $bar['count'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="pdash-chart-legend">
          <span>Daily visit counts</span>
          <strong><?= htmlspecialchars(end($week_chart)['date'] ?? 'Today') ?> = today</strong>
        </div>
      </section>

      <section class="pdash-card" id="dashboardUpcomingCard">
        <div class="pdash-card__head">
          <h2 class="pdash-card__title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Upcoming Consultations
          </h2>
          <span class="pdash-card__badge"><?= count($upcoming_list) ?> scheduled</span>
        </div>
        <?php if (!empty($upcoming_list)): ?>
        <div class="pdash-sessions">
          <?php foreach (array_slice($upcoming_list, 0, 4) as $c):
            $sched_date = !empty($c['consult_date']) ? date('M j, Y', strtotime($c['consult_date'])) : '—';
            $sched_time = !empty($c['consult_time']) ? date('g:i A', strtotime($c['consult_time'])) : '—';
            $provider_name = htmlspecialchars($c['provider_name'] ?? '—');
            $provider_initials = $pt_dash_provider_initials($c['provider_name'] ?? '');
            $join_access = consultation_patient_join_access($c);
            $card_class = $join_access['allowed'] ? ' pdash-session--ready' : '';
          ?>
          <article class="pdash-session<?= $card_class ?>">
            <div class="pdash-session__main">
              <span class="pdash-session__avatar"><?= htmlspecialchars($provider_initials) ?></span>
              <div class="pdash-session__info">
                <h4>Dr. <?= $provider_name ?></h4>
                <p class="pdash-session__meta"><?= htmlspecialchars($c['consult_type'] ?? 'Video Consultation') ?></p>
                <p class="pdash-session__datetime"><?= $sched_date ?> · <?= $sched_time ?></p>
              </div>
            </div>
            <div class="pdash-session__action" data-consult-action="<?= (int) ($c['id'] ?? 0) ?>">
              <?php if ($join_access['allowed']): ?>
              <a href="<?= htmlspecialchars($video_base) ?>?token=<?= urlencode($c['room_token']) ?>" class="pdash-btn pdash-btn--join pdash-btn--sm">Join Call</a>
              <?php elseif ($join_access['mode'] === 'scheduled_wait'): ?>
              <span class="pdash-btn pdash-btn--waiting pdash-btn--sm" title="<?= htmlspecialchars($join_access['reason'], ENT_QUOTES, 'UTF-8') ?>">
                Opens at <?= htmlspecialchars(queue_session_context($c)['opens_at_label'] ?: 'scheduled time') ?>
              </span>
              <?php elseif ($join_access['mode'] === 'waiting'): ?>
              <span class="pdash-btn pdash-btn--waiting pdash-btn--sm consult-waiting-pulse" title="<?= htmlspecialchars($join_access['reason'], ENT_QUOTES, 'UTF-8') ?>">
                Waiting for Provider
              </span>
              <?php else: ?>
              <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="pdash-btn pdash-btn--outline pdash-btn--sm">View Details</a>
              <?php endif; ?>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <?php if (count($upcoming_list) > 4): ?>
        <div class="pdash-card__foot">
          <a href="<?= ASSET_BASE ?>/views/patient/consultations.php">View all <?= count($upcoming_list) ?> sessions →</a>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="pdash-empty">
          <div class="pdash-empty__icon" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
          </div>
          <p>No upcoming consultations scheduled.</p>
          <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pdash-btn pdash-btn--primary">Book Consultation</a>
        </div>
        <?php endif; ?>
      </section>

      <?php require __DIR__ . '/dashboard_action_items.php'; ?>
    </div>

    <aside class="pdash-side">

      <details class="pdash-flow" open>
        <summary class="pdash-flow__summary">
          <span class="pdash-flow__summary-icon" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
          </span>
          How it works
        </summary>
        <ol class="pdash-flow__steps">
          <li><span class="pdash-flow__num">1</span><span><strong>Register</strong> — verify your identity and share your health concern.</span></li>
          <li><span class="pdash-flow__num">2</span><span><strong>Book</strong> — choose a provider and an available time slot.</span></li>
          <li><span class="pdash-flow__num">3</span><span><strong>Join</strong> — enter the secure video room when your session opens.</span></li>
        </ol>
      </details>

      <section class="pdash-card" data-notif-widgets aria-label="Attention items">
        <div class="pdash-card__head">
          <h2 class="pdash-card__title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Needs Attention
          </h2>
        </div>
        <div class="pdash-alerts">
          <div class="pdash-alert-widget" data-widget="pending_referrals">
            <span class="pdash-alert-widget__label">Pending Referrals</span>
            <span class="pdash-alert-widget__value mc-notif-widget-value">0</span>
          </div>
          <div class="pdash-alert-widget pdash-alert-widget--emergency" data-widget="emergency_alerts" id="pdashEmergencyWidget">
            <span class="pdash-alert-widget__label">Emergency Alerts</span>
            <span class="pdash-alert-widget__value mc-notif-widget-value">0</span>
          </div>
        </div>
      </section>

      <section class="pdash-card">
        <div class="pdash-card__head">
          <h2 class="pdash-card__title">Live Status</h2>
        </div>
        <div class="pdash-status-list">
          <div class="pdash-status-item">
            <span class="pdash-status-item__label">
              <span class="pdash-status-dot" style="background:#3b82f6;"></span>
              In Consultation
            </span>
            <strong><?= (int) $dash_in_consultation ?></strong>
          </div>
          <div class="pdash-status-item">
            <span class="pdash-status-item__label">
              <span class="pdash-status-dot" style="background:#22c55e;"></span>
              Health Files
            </span>
            <strong><?= (int) $dash_records_count ?></strong>
          </div>
          <div class="pdash-status-item <?= $dash_urgent_triage > 0 ? 'pdash-status-item--urgent' : '' ?>">
            <span class="pdash-status-item__label">
              <span class="pdash-status-dot" style="background:#ef4444;"></span>
              Priority Visits
            </span>
            <strong><?= (int) $dash_urgent_triage ?></strong>
          </div>
        </div>
      </section>

    </aside>
  </div>
</div>
