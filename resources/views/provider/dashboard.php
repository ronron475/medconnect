<?php
/**
 * medConnect Clinical Portal - Provider Dashboard
 */
$active_page = 'dashboard';
$page_title  = 'Clinical Dashboard';
$page_styles = ['provider-dashboard-home.css', 'provider_session_alert.css'];

require __DIR__.'/partials/icons.php';
require __DIR__.'/partials/data.php';
require __DIR__.'/partials/queue_helpers.php';
require __DIR__.'/partials/layout_open.php';

require_once BASE_PATH . '/app/includes/provider_dashboard_live.php';
require_once BASE_PATH . '/app/includes/consultation_video_history.php';

$queue = $queue ?? [];
$stats = $stats ?? [];
$provider_id = (int) ($_SESSION['user_id'] ?? 0);
$chart_period = provider_parse_dashboard_period($_GET['period'] ?? 'week');
$chart_data = provider_dashboard_consultation_chart($pdo, $provider_id, $chart_period);
$week_chart = $chart_data['series'];
$week_total = $chart_data['total'];

$recordings = consultation_provider_recent_recordings($pdo, $provider_id, 5);

$display_name = $provider['display_name'] ?? trim(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? ''));
$last_name = $provider['last_name'] ?? 'Provider';
?>

<div class="prov-dash" data-live-dashboard>

  <!-- Welcome -->
  <section class="prov-dash-welcome prov-dash-welcome--compact">
    <div class="prov-dash-welcome__left">
      <a href="<?= ASSET_BASE ?>/views/provider/settings.php" data-profile-avatar-wrap title="Profile settings" style="text-decoration:none;flex-shrink:0;">
        <?= profile_picture_render($provider['initials'] ?? 'DR', $provider['picture_url'] ?? null, '', 'sm') ?>
      </a>
      <div class="prov-dash-welcome__text">
        <div class="prov-dash-welcome__eyebrow"><?= htmlspecialchars($greeting) ?>, Dr. <?= htmlspecialchars($last_name) ?></div>
        <span class="prov-dash-staff-id">Staff ID: <strong>MC-<?= str_pad((string) $provider_id, 5, '0', STR_PAD_LEFT) ?></strong></span>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <span class="text-xs text-muted" data-live-sync aria-live="polite">Live</span>
      <span class="prov-dash-badge">Active Duty</span>
    </div>
  </section>

  <section class="prov-dash-metrics prov-dash-metrics--unified" data-notif-widgets aria-label="Operations summary">
    <div class="prov-dash-stat prov-dash-stat--ok">
      <span class="prov-dash-stat__icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
      </span>
      <strong data-live-stat="appointments"><?= (int) ($stats['appointments'] ?? 0) ?></strong>
      <span>Today's Appointments</span>
    </div>
    <div class="prov-dash-stat prov-dash-stat--warn">
      <span class="prov-dash-stat__icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      </span>
      <strong data-live-stat="pending"><?= (int) ($stats['pending'] ?? 0) ?></strong>
      <span>Waiting in Queue</span>
    </div>
    <div class="prov-dash-stat">
      <span class="prov-dash-stat__icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </span>
      <strong data-live-stat="ongoing"><?= (int) ($stats['ongoing'] ?? 0) ?></strong>
      <span>In Consultation</span>
    </div>
    <div class="prov-dash-stat">
      <span class="prov-dash-stat__icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </span>
      <strong data-live-stat="completed"><?= (int) ($stats['completed'] ?? 0) ?></strong>
      <span>Completed (Month)</span>
    </div>
    <?php
    $notif_widget_mode = 'strip';
    $notif_widget_bare = true;
    $notif_widget_exclude = ['today_appointments'];
    require VIEWS_PATH . '/partials/notification_widgets.php';
    ?>
  </section>

  <div class="prov-dash-grid">

    <!-- Main column -->
    <div class="prov-dash-main">

      <!-- Weekly chart -->
      <section class="prov-dash-card">
        <div class="prov-dash-card__head">
          <h3 class="prov-dash-card__title"><?= icon('activity') ?> Consultation Activity</h3>
          <div class="prov-dash-chart-toolbar">
            <span class="mc-badge" data-live-chart-total><?= (int) $week_total ?> <?= htmlspecialchars($chart_data['total_label']) ?></span>
            <select id="provDashChartPeriod" class="prov-dash-chart-period" aria-label="Chart period">
              <option value="today"<?= $chart_period === 'today' ? ' selected' : '' ?>>Today</option>
              <option value="week"<?= $chart_period === 'week' ? ' selected' : '' ?>>This week</option>
              <option value="month"<?= $chart_period === 'month' ? ' selected' : '' ?>>This month</option>
              <option value="year"<?= $chart_period === 'year' ? ' selected' : '' ?>>This year</option>
            </select>
          </div>
        </div>
        <p class="prov-dash-card__sub" data-live-chart-sub>Consultation activity — <?= htmlspecialchars(strtolower($chart_data['period_label'])) ?> · auto-refreshes</p>
        <div class="mc-chart-canvas-wrap" style="min-height:220px;height:220px;">
          <canvas data-mc-weekly-bar="provWeekChartData" aria-label="Consultation activity bar chart"></canvas>
        </div>
        <script type="application/json" id="provWeekChartData"><?= json_encode($week_chart, JSON_UNESCAPED_UNICODE) ?></script>
        <div class="prov-chart-legend">
          <span data-live-chart-hint>Hover bars for counts</span>
          <strong data-live-week-today><?= htmlspecialchars(end($week_chart)['date'] ?? 'Today') ?><?= $chart_period === 'year' ? '' : ' = today' ?></strong>
        </div>
      </section>

      <!-- Queue -->
      <section class="prov-dash-card prov-dash-table">
        <div class="prov-dash-card__head">
          <h3 class="prov-dash-card__title"><?= icon('users') ?> Upcoming Consultations</h3>
          <span class="mc-badge" data-live-queue-count><?= count($queue) ?> pending</span>
        </div>
        <div data-live-queue>
        <?php if (!empty($queue)): ?>
        <div class="table-responsive">
          <table class="mc-table">
            <thead>
              <tr>
                <th>Patient</th>
                <th>Complaint</th>
                <th>Priority</th>
                <th>Schedule</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($queue as $item):
                $urg = strtolower($item['urgency'] ?? '');
                $is_urgent = str_contains($urg, 'urgent') || str_contains($urg, '1') || str_contains($urg, 'emergency');
                $urg_bg = $is_urgent ? '#fee2e2' : '#e0f2fe';
                $urg_color = $is_urgent ? '#ef4444' : '#0369a1';
                $sched_date = !empty($item['date']) ? date('M j, Y', strtotime($item['date'])) : 'Today';
                $sched_time = !empty($item['time']) ? date('g:i A', strtotime($item['time'])) : '';
                $session_access = queue_session_access([
                    'status'       => $item['raw_status'] ?? 'pending',
                    'consult_date' => $item['date'] ?? '',
                    'consult_time' => $item['time'] ?? '',
                    'slot_date'    => $item['slot_date'] ?? '',
                    'slot_start'   => $item['slot_start'] ?? '',
                ]);
              ?>
              <tr>
                <td style="font-weight:700;"><?= htmlspecialchars($item['patient_name'] ?? 'Patient') ?></td>
                <td class="text-muted"><?= htmlspecialchars($item['complaint'] ?? 'General Consultation') ?></td>
                <td>
                  <span style="background:<?= $urg_bg ?>;color:<?= $urg_color ?>;padding:4px 8px;border-radius:6px;font-size:10px;font-weight:700;text-transform:uppercase;">
                    <?= htmlspecialchars($item['urgency'] ?? 'Routine') ?>
                  </span>
                </td>
                <td style="font-size:12px;white-space:nowrap;">
                  <div style="font-weight:700;"><?= $sched_date ?></div>
                  <?php if ($sched_time): ?>
                  <div class="text-muted" style="font-size:11px;"><?= $sched_time ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($session_access['allowed']): ?>
                  <a href="<?= ASSET_BASE ?>/views/provider/consultation_session.php?id=<?= (int) ($item['id'] ?? 0) ?>" class="mc-btn mc-btn--primary" style="padding:4px 12px;font-size:10px;white-space:nowrap;">
                    Start Session
                  </a>
                  <?php else: ?>
                  <button type="button" class="mc-btn mc-btn--outline queue-open-session-blocked" style="padding:4px 12px;font-size:10px;white-space:nowrap;opacity:.65;" data-reason="<?= htmlspecialchars($session_access['reason'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($session_access['reason'], ENT_QUOTES, 'UTF-8') ?>">
                    Start Session
                  </button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="mc-table-empty">
          <?= icon('activity') ?>
          <p>No pending consultations in your queue.</p>
          <a href="<?= ASSET_BASE ?>/views/provider/queue.php" class="mc-btn mc-btn--outline prov-dash-empty-cta">Open Live Queue</a>
        </div>
        <?php endif; ?>
        </div>
      </section>

      <!-- Recent notifications -->
      <div class="prov-dash-notif-wrap">
        <?php
        $notif_widget_mode = 'recent';
        $notif_widget_skin = 'provider';
        require VIEWS_PATH . '/partials/notification_widgets.php';
        ?>
      </div>
    </div>

    <!-- Sidebar column -->
    <aside class="prov-dash-side">

      <section class="prov-dash-card prov-dash-cta">
        <h3 class="prov-dash-card__title">AI Triage Engine</h3>
        <p>Prioritize critical cases from automated symptom assessments.</p>
        <a href="<?= ASSET_BASE ?>/views/provider/triage.php" class="mc-btn">Review Triage</a>
      </section>

      <section class="prov-dash-card">
        <div class="prov-dash-card__head">
          <h3 class="prov-dash-card__title">Live Status</h3>
        </div>
        <div class="prov-status-list">
          <div class="prov-status-item">
            <span class="prov-status-item__label">
              <span class="prov-status-dot" style="background:#fbbf24;"></span>
              Waiting
            </span>
            <strong data-live-status="waiting"><?= (int) ($stats['pending'] ?? 0) ?></strong>
          </div>
          <div class="prov-status-item">
            <span class="prov-status-item__label">
              <span class="prov-status-dot" style="background:#3b82f6;"></span>
              In Consultation
            </span>
            <strong data-live-status="ongoing"><?= (int) ($stats['ongoing'] ?? 0) ?></strong>
          </div>
          <div class="prov-status-item">
            <span class="prov-status-item__label">
              <span class="prov-status-dot" style="background:#22c55e;"></span>
              Completed (month)
            </span>
            <strong data-live-status="completed"><?= (int) ($stats['completed'] ?? 0) ?></strong>
          </div>
          <div class="prov-status-item" data-live-slot-wait-wrap<?= empty($stats['slot_waiting']) ? ' hidden' : '' ?>>
            <span class="prov-status-item__label">
              <span class="prov-status-dot" style="background:#f59e0b;"></span>
              Waiting for Provider Availability
            </span>
            <strong data-live-status="slot_waiting"><?= (int) ($stats['slot_waiting'] ?? 0) ?></strong>
          </div>
          <div class="prov-status-item" data-live-urgent-wrap style="border-color:#fecaca;background:#fef2f2;"<?= empty($stats['urgent']) ? ' hidden' : '' ?>>
            <span class="prov-status-item__label">
              <span class="prov-status-dot" style="background:#ef4444;"></span>
              Urgent Triage
            </span>
            <strong style="color:#dc2626;" data-live-status="urgent"><?= (int) ($stats['urgent'] ?? 0) ?></strong>
          </div>
        </div>
      </section>

      <section class="prov-dash-card">
        <div class="prov-dash-card__head">
          <h3 class="prov-dash-card__title"><?= icon('activity') ?> Recent Activity</h3>
        </div>
        <div data-live-activity>
        <?php if (empty($activity)): ?>
        <p class="text-xs text-muted" style="text-align:center;padding:12px 0;margin:0;">No recent activity yet.</p>
        <?php else: ?>
        <div class="prov-activity-list">
          <?php foreach ($activity as $act): ?>
          <div class="prov-activity-item">
            <span class="prov-activity-item__icon" aria-hidden="true"><?= icon((string) ($act['icon'] ?? 'activity')) ?></span>
            <div class="prov-activity-item__body">
              <div class="prov-activity-item__msg"><?= htmlspecialchars((string) ($act['msg'] ?? '')) ?></div>
              <div class="prov-activity-item__time"><?= htmlspecialchars((string) ($act['time'] ?? '')) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div>
      </section>

      <section class="prov-dash-card">
        <div class="prov-dash-card__head">
          <h3 class="prov-dash-card__title"><?= icon('video') ?> Session Recordings</h3>
        </div>
        <?php if (empty($recordings)): ?>
        <p class="text-xs text-muted" style="text-align:center;padding:12px 0;margin:0;">No recordings yet.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($recordings as $rec):
            $recUrl = (string) ($rec['view_url'] ?? '');
            if ($recUrl === '') {
                continue;
            }
            $segCount = (int) ($rec['segment_count'] ?? 1);
          ?>
          <div class="prov-recording-item">
            <div>
              <div class="prov-recording-item__name"><?= htmlspecialchars((string) ($rec['patient_name'] ?? 'Patient')) ?></div>
              <div class="prov-recording-item__date"><?= htmlspecialchars((string) (($rec['ended_label'] ?? '') !== '' ? $rec['ended_label'] : ('Consultation #' . (int) ($rec['consultation_id'] ?? 0)))) ?></div>
              <?php if ($segCount > 1): ?>
              <div class="prov-recording-item__date"><?= $segCount ?> recording segments</div>
              <?php endif; ?>
            </div>
            <div class="prov-recording-actions">
              <a href="<?= htmlspecialchars($recUrl) ?>" target="_blank" rel="noopener" class="mc-btn mc-btn--outline" style="padding:4px 8px;font-size:10px;">View</a>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>

    </aside>
  </div>
</div>

<?php require __DIR__ . '/partials/session_schedule_modal.php'; ?>
<script src="<?= ASSET_BASE ?>/assets/js/provider-session-alert.js"></script>
<?php
$mc_chart_css_ver = (int) @filemtime(ASSETS_PATH . '/css/medconnect-charts.css');
$mc_chart_theme_ver = (int) @filemtime(ASSETS_PATH . '/js/medconnect-chart-theme.js');
$mc_portal_charts_ver = (int) @filemtime(ASSETS_PATH . '/js/medconnect-portal-charts.js');
$mc_dash_live_ver = (int) @filemtime(ASSETS_PATH . '/js/provider-dashboard-live.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/medconnect-charts.css?v=<?= $mc_chart_css_ver ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-chart-theme.js?v=<?= $mc_chart_theme_ver ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/medconnect-portal-charts.js?v=<?= $mc_portal_charts_ver ?>"></script>
<script src="<?= ASSET_BASE ?>/assets/js/provider-dashboard-live.js?v=<?= $mc_dash_live_ver ?>"></script>
<?php require __DIR__.'/partials/layout_close.php'; ?>
