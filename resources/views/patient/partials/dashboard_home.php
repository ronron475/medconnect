<?php
/**
 * Patient dashboard home — redesigned layout.
 */
$pt_last = trim((string) ($pt['last_name'] ?? ''));
$pt_display_raw = trim((string) ($pt['first_name'] ?? ''));
if ($pt_last !== '' && stripos($pt_display_raw, $pt_last) === false) {
    $pt_display_raw = trim($pt_display_raw . ' ' . $pt_last);
}
if ($pt_display_raw === '') {
    $pt_display_raw = 'Patient';
}
$pt_display = htmlspecialchars($pt_display_raw);
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

$has_active_consultation = !empty($active_consultation)
    && in_array(strtolower((string) ($active_consultation['status'] ?? '')), ['pending', 'scheduled', 'waiting', 'in_consultation'], true);
$active_consult_id = $has_active_consultation ? (int) ($active_consultation['id'] ?? 0) : 0;
// Active consultation card already has the join CTA — avoid a second Join button in the live strip.
$show_dash_live_strip = $dash_live_session && $dash_live_join && !$has_active_consultation;
?>
<div id="view-dashboard" class="patient-page pdash-page">

  <section
    class="pdash-hero pdash-hero--welcome"
    aria-label="Welcome"
    data-pdash-greeting
    data-patient-name="<?= $pt_display ?>"
  >
    <div class="pdash-hero__main">
      <div class="pdash-hero__content">
        <h1 class="pdash-hero__title">
          <span data-pdash-greeting-period>Good morning</span>, <?= $pt_display ?>
        </h1>
        <div class="pdash-hero__badges">
          <?php if ($pt_is_verified): ?>
          <span class="pdash-badge pdash-badge--verified">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              <path d="m9 12 2 2 4-4"/>
            </svg>
            Verified Patient
          </span>
          <?php else: ?>
          <span class="pdash-badge pdash-badge--pending"><?= htmlspecialchars($pt_status_label) ?></span>
          <?php endif; ?>
          <span class="pdash-badge pdash-badge--id">Patient ID: <strong><?= $patient_id_label ?></strong></span>
        </div>
      </div>
    </div>
    <div class="pdash-hero__actions">
      <?php if (!empty($active_consultation) && in_array(strtolower((string) ($active_consultation['status'] ?? '')), ['pending', 'scheduled', 'waiting', 'in_consultation'], true)): ?>
      <a href="#pdashActiveConsultation" class="pdash-btn pdash-btn--primary" id="pdashHeroPrimary">View Active Consultation</a>
      <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="pdash-btn pdash-btn--outline">My Sessions</a>
      <?php elseif (!empty($slot_wait_state['active']) && ($slot_wait_state['status'] ?? '') === 'slot_available'): ?>
      <a href="#pdashSlotWait" class="pdash-btn pdash-btn--primary" id="pdashHeroPrimary">Book Consultation</a>
      <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="pdash-btn pdash-btn--outline">My Sessions</a>
      <?php elseif (!empty($slot_wait_state['active'])): ?>
      <a href="#pdashSlotWait" class="pdash-btn pdash-btn--primary" id="pdashHeroPrimary">View waiting status</a>
      <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="pdash-btn pdash-btn--outline">My Sessions</a>
      <?php elseif (!empty($care_tips_ready_to_schedule['ready'])): ?>
      <a href="#pdashCareTipsReady" class="pdash-btn pdash-btn--primary" id="pdashHeroPrimary">Book Consultation</a>
      <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="pdash-btn pdash-btn--outline">My Sessions</a>
      <?php elseif (!empty($symptoms_review_pending['has_pending'])): ?>
      <a href="#pdashSymptomsReview" class="pdash-btn pdash-btn--primary" id="pdashHeroPrimary">View Review Status</a>
      <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="pdash-btn pdash-btn--outline">My Sessions</a>
      <?php else: ?>
      <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pdash-btn pdash-btn--primary" id="pdashHeroPrimary">Book New Consultation</a>
      <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="pdash-btn pdash-btn--outline">My Sessions</a>
      <?php endif; ?>
    </div>
  </section>
  <script>
  (function () {
    var root = document.querySelector('[data-pdash-greeting]');
    if (!root) return;
    var h = new Date().getHours();
    var period = h < 12 ? 'morning' : (h < 18 ? 'afternoon' : 'evening');
    var label = period === 'afternoon' ? 'Good afternoon' : (period === 'evening' ? 'Good evening' : 'Good morning');
    var el = root.querySelector('[data-pdash-greeting-period]');
    if (el) el.textContent = label;
    root.setAttribute('data-greeting-period', period);
  })();
  </script>

  <?php if ($show_dash_live_strip): ?>
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
    <button type="button" class="pdash-btn pdash-btn--join pdash-btn--sm" data-mc-video-join
      data-token="<?= htmlspecialchars($dash_live_session['room_token'], ENT_QUOTES, 'UTF-8') ?>"
      data-consultation-id="<?= (int) ($dash_live_session['id'] ?? 0) ?>"
      data-label="Consultation with Dr. <?= htmlspecialchars($dash_live_session['provider_name'] ?? 'your provider', ENT_QUOTES, 'UTF-8') ?>">Join Consultation</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div id="pdashPrimaryCard">
  <?php
  $symptoms_review_has_pending = !empty($symptoms_review_pending['has_pending']);
  $care_tips_ready = !empty($care_tips_ready_to_schedule['ready']);
  $has_slot_wait = !empty($slot_wait_state['active']);

  // Active scheduled/in-progress visit always wins over care-tips review / new complaint.
  if ($has_active_consultation):
      require __DIR__ . '/dashboard_active_consultation.php';
  elseif ($has_slot_wait):
      require __DIR__ . '/dashboard_slot_wait.php';
  elseif ($symptoms_review_has_pending && !empty($show_dashboard_care_tips_section)):
      require __DIR__ . '/dashboard_symptoms_review.php';
  elseif ($care_tips_ready):
      require __DIR__ . '/dashboard_ready_to_schedule.php';
  else:
      require __DIR__ . '/dashboard_chief_complaint.php';
  endif;
  ?>
  </div>

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

  <div class="pdash-grid">
    <div class="pdash-main">

      <?php require __DIR__ . '/dashboard_action_items.php'; ?>

      <section class="pdash-card">
        <div class="pdash-card__head">
          <h2 class="pdash-card__title">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            Consultation Activity
          </h2>
          <span class="pdash-card__badge"><?= (int) $week_total ?> this week</span>
        </div>
        <p class="pdash-card__sub">Your consultations over the last 7 days</p>
        <div class="mc-chart-canvas-wrap" style="min-height:220px;height:220px;">
          <canvas data-mc-weekly-bar="pdashWeekChartData" aria-label="Weekly consultation bar chart"></canvas>
        </div>
        <script type="application/json" id="pdashWeekChartData"><?= json_encode($week_chart, JSON_UNESCAPED_UNICODE) ?></script>
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
                <?php
                  $dashOutcome = null;
                  if (isset($pdo) && $pdo instanceof PDO && !empty($c['id'])) {
                      if (!function_exists('patient_consultation_clinical_outcome')) {
                          require_once BASE_PATH . '/app/includes/patient_consultation_records.php';
                      }
                      $dashOutcome = patient_consultation_clinical_outcome($pdo, (int) $c['id'], (int) ($_SESSION['user_id'] ?? 0), false);
                  }
                  if (!empty($dashOutcome['ai_case_level']) || !empty($dashOutcome['ai_case_display']) || !empty($dashOutcome['final_case_level'])):
                      if (!function_exists('mc_render_consultation_outcome_stack')) {
                          require_once VIEWS_PATH . '/patient/partials/triage_helpers.php';
                      }
                      mc_render_consultation_outcome_stack($dashOutcome, (int) $c['id']);
                  endif;
                ?>
              </div>
            </div>
            <div class="pdash-session__action" data-consult-action="<?= (int) ($c['id'] ?? 0) ?>">
              <?php
                $session_id = (int) ($c['id'] ?? 0);
                $is_active_row = $active_consult_id > 0 && $session_id === $active_consult_id;
              ?>
              <?php if ($join_access['allowed'] && !$is_active_row): ?>
              <button type="button" class="pdash-btn pdash-btn--join pdash-btn--sm" data-mc-video-join
                data-token="<?= htmlspecialchars($c['room_token'], ENT_QUOTES, 'UTF-8') ?>"
                data-consultation-id="<?= $session_id ?>"
                data-label="Consultation with Dr. <?= htmlspecialchars($provider_name, ENT_QUOTES, 'UTF-8') ?>">Join Consultation</button>
              <?php elseif ($is_active_row): ?>
              <a href="#pdashActiveConsultation" class="pdash-btn pdash-btn--outline pdash-btn--sm">View Appointment</a>
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
          <?php if (!empty($has_active_consultation)): ?>
          <a href="#pdashActiveConsultation" class="pdash-btn pdash-btn--primary">View Active Consultation</a>
          <?php else: ?>
          <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="pdash-btn pdash-btn--primary">Book New Consultation</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </section>

    </div>
  </div>
</div>
