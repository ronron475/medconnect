<?php
/**
 * Patient dashboard home — reference mockup layout.
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
?>
      <div id="view-dashboard" class="view-container active prov-dash">

        <section class="adm-banner patient-dash-banner" aria-label="Welcome">
          <div class="adm-banner-inner">
            <div class="adm-banner-eyebrow">Patient Care Portal</div>
            <h1 class="adm-banner-title">Welcome, <?= $pt_first ?></h1>
            <p class="adm-banner-sub">Manage your appointments, triage history, prescriptions, and health records in one secure place.</p>
          </div>
          <div class="patient-dash-banner-actions">
            <span class="mc-badge <?= $pt_is_verified ? 'mc-badge--patient' : 'patient-dash-pill patient-dash-pill--pending' ?>"><?= htmlspecialchars($pt_status_label) ?></span>
            <span class="patient-dash-pill patient-dash-pill--id">Patient ID: <strong><?= $patient_id_label ?></strong></span>
          </div>
        </section>

        <div class="prov-dash-metrics">
        <!-- Quick stats -->
        <section class="prov-dash-stats" aria-label="Health summary">
          <div class="prov-dash-stat prov-dash-stat--ok">
            <span class="prov-dash-stat__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
            </span>
            <strong><?= (int) $dash_today_appts ?></strong>
            <span>Today's Appointments</span>
          </div>
          <div class="prov-dash-stat <?= $dash_upcoming_count > 0 ? 'prov-dash-stat--warn' : '' ?>">
            <span class="prov-dash-stat__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </span>
            <strong><?= (int) $dash_upcoming_count ?></strong>
            <span>Upcoming Consultations</span>
          </div>
          <div class="prov-dash-stat">
            <span class="prov-dash-stat__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            </span>
            <strong><?= (int) $dash_triage_count ?></strong>
            <span>Triage Assessments</span>
          </div>
          <div class="prov-dash-stat">
            <span class="prov-dash-stat__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <strong><?= (int) $dash_completed_month ?></strong>
            <span>Completed This Month</span>
          </div>
        </section>

        <!-- How it works + live widgets -->
        <section class="prov-dash-widget-strip" data-notif-widgets aria-label="Live status widgets">
          <article class="prov-how-it-works">
            <div class="prov-how-it-works__label">How it works</div>
            <div class="prov-how-it-works__player">
              <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="prov-how-it-works__play" aria-label="Open triage assessment">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
              </a>
              <div class="prov-how-it-works__track">
                <div class="prov-how-it-works__bar"><span class="prov-how-it-works__fill"></span></div>
                <div class="prov-how-it-works__time">1:24 / 3:45</div>
              </div>
            </div>
          </article>
          <?php
          $notif_widget_mode = 'strip';
          $notif_widget_exclude = ['unread_count'];
          $notif_widget_bare = true;
          require VIEWS_PATH . '/partials/notification_widgets.php';
          ?>
        </section>
        </div>

        <div class="prov-dash-grid">

          <!-- Main column -->
          <div class="prov-dash-main">

            <!-- Weekly chart -->
            <section class="prov-dash-card">
              <div class="prov-dash-card__head">
                <h3 class="prov-dash-card__title">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                  Consultation Activity
                </h3>
                <span class="mc-badge"><?= (int) $week_total ?> this week</span>
              </div>
              <p class="prov-dash-card__sub">Your consultations over the last 7 days</p>
              <div class="prov-chart" role="img" aria-label="Weekly consultation bar chart">
                <?php foreach ($week_chart as $bar):
                  $pct = ($bar['count'] / $chart_max) * 100;
                  $height = max(6, round($pct));
                ?>
                <div class="prov-chart-col <?= $bar['is_today'] ? 'is-today' : '' ?>">
                  <div class="prov-chart-bar-wrap">
                    <div class="prov-chart-bar" style="height: <?= $height ?>%;" title="<?= (int) $bar['count'] ?> on <?= htmlspecialchars($bar['date']) ?>"></div>
                  </div>
                  <span class="prov-chart-label"><?= htmlspecialchars($bar['label']) ?></span>
                  <span class="prov-chart-val"><?= (int) $bar['count'] ?></span>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="prov-chart-legend">
                <span>Hover bars for daily counts</span>
                <strong><?= htmlspecialchars(end($week_chart)['date'] ?? 'Today') ?> = today</strong>
              </div>
            </section>

            <!-- Upcoming consultations -->
            <section class="prov-dash-card prov-dash-table" id="dashboardUpcomingCard">
              <div class="prov-dash-card__head">
                <h3 class="prov-dash-card__title">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                  Upcoming Consultations
                </h3>
                <span class="mc-badge"><?= count($upcoming_list) ?> pending</span>
              </div>
              <?php if (!empty($upcoming_list)): ?>
              <div class="table-responsive">
                <table class="mc-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Time</th>
                      <th>Provider</th>
                      <th>Type</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach (array_slice($upcoming_list, 0, 5) as $c):
                      $sched_date = !empty($c['consult_date']) ? date('M j, Y', strtotime($c['consult_date'])) : '—';
                      $sched_time = !empty($c['consult_time']) ? date('g:i A', strtotime($c['consult_time'])) : '—';
                      $provider_name = htmlspecialchars($c['provider_name'] ?? '—');
                      $provider_initials = $pt_dash_provider_initials($c['provider_name'] ?? '');
                    ?>
                    <tr>
                      <td style="font-weight:700;white-space:nowrap;"><?= $sched_date ?></td>
                      <td style="white-space:nowrap;"><?= $sched_time ?></td>
                      <td>
                        <div class="prov-dash-provider">
                          <?= profile_picture_render($provider_initials, null, '', 'xs') ?>
                          <span><?= $provider_name ?></span>
                        </div>
                      </td>
                      <td class="text-muted"><?= htmlspecialchars($c['consult_type'] ?? 'Video Consultation') ?></td>
                      <td>
                        <?php if (!empty($c['room_token'])): ?>
                        <a href="<?= htmlspecialchars($video_base) ?>?token=<?= urlencode($c['room_token']) ?>" class="prov-dash-action-btn" style="text-decoration:none;">
                          Join Call
                        </a>
                        <?php else: ?>
                        <a href="<?= ASSET_BASE ?>/views/patient/consultations.php" class="prov-dash-action-btn" style="text-decoration:none;">Confirm / Reschedule</a>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php else: ?>
              <div class="mc-table-empty">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.35;" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                <p>No upcoming consultations scheduled.</p>
                <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="mc-btn mc-btn--outline prov-dash-empty-cta" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">Book Consultation</a>
              </div>
              <?php endif; ?>
            </section>
          </div>

          <!-- Sidebar column -->
          <aside class="prov-dash-side">

            <section class="prov-dash-card prov-dash-cta">
              <h3 class="prov-dash-card__title">AI Health Assessment</h3>
              <p>Complete a symptom check and book your next consultation slot.</p>
              <a href="<?= ASSET_BASE ?>/views/patient/triage.php" class="mc-btn" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;">Start Assessment</a>
            </section>

            <section class="prov-dash-card">
              <div class="prov-dash-card__head">
                <h3 class="prov-dash-card__title">Live Status</h3>
              </div>
              <div class="prov-status-list">
                <div class="prov-status-item">
                  <span class="prov-status-item__label">
                    <span class="prov-status-dot" style="background:#fbbf24;"></span>
                    Upcoming
                  </span>
                  <strong><?= (int) $dash_upcoming_count ?></strong>
                </div>
                <div class="prov-status-item">
                  <span class="prov-status-item__label">
                    <span class="prov-status-dot" style="background:#3b82f6;"></span>
                    In Consultation
                  </span>
                  <strong><?= (int) $dash_in_consultation ?></strong>
                </div>
                <div class="prov-status-item">
                  <span class="prov-status-item__label">
                    <span class="prov-status-dot" style="background:#22c55e;"></span>
                    Completed (Month)
                  </span>
                  <strong><?= (int) $dash_completed_month ?></strong>
                </div>
                <div class="prov-status-item" style="<?= $dash_urgent_triage > 0 ? 'background:#fef2f2;border-color:#fecaca;' : '' ?>">
                  <span class="prov-status-item__label">
                    <span class="prov-status-dot" style="background:#ef4444;"></span>
                    Urgent Triage
                  </span>
                  <strong style="<?= $dash_urgent_triage > 0 ? 'color:#dc2626;' : '' ?>"><?= (int) $dash_urgent_triage ?></strong>
                </div>
              </div>
            </section>

          </aside>
        </div>
      </div>
