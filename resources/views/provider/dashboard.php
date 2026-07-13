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

$queue = $queue ?? [];
$stats = $stats ?? [];
$provider_id = (int) ($_SESSION['user_id'] ?? 0);

// Weekly consultation activity (last 7 days)
$week_chart = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $count = 0;
    try {
        $c_stmt = $pdo->prepare('SELECT COUNT(*) FROM consultations WHERE provider_id = ? AND consult_date = ?');
        $c_stmt->execute([$provider_id, $date]);
        $count = (int) $c_stmt->fetchColumn();
    } catch (Exception $e) {
        $count = 0;
    }
    $week_chart[] = [
        'label'    => date('D', strtotime($date)),
        'date'     => date('M j', strtotime($date)),
        'count'    => $count,
        'is_today' => ($i === 0),
    ];
}
$chart_max = max(1, ...array_column($week_chart, 'count'));
$week_total = array_sum(array_column($week_chart, 'count'));

// Session recordings
$recordings = [];
try {
    $rec_stmt = $pdo->prepare("
        SELECT vs.recording_url, vs.ended_at, u.first_name, u.last_name
        FROM video_sessions vs
        JOIN consultations c ON vs.consultation_id = c.id
        JOIN users u ON c.patient_id = u.id
        WHERE c.provider_id = ? AND vs.recording_url IS NOT NULL
        ORDER BY vs.ended_at DESC
        LIMIT 5
    ");
    $rec_stmt->execute([$provider_id]);
    $recordings = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recordings = [];
}

$display_name = $provider['display_name'] ?? trim(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? ''));
$last_name = $provider['last_name'] ?? 'Provider';
?>

<div class="prov-dash">

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
    <span class="prov-dash-badge">Active Duty</span>
  </section>

  <section class="prov-dash-metrics prov-dash-metrics--unified" data-notif-widgets aria-label="Operations summary">
    <div class="prov-dash-stat prov-dash-stat--ok">
      <span class="prov-dash-stat__icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
      </span>
      <strong><?= (int) ($stats['appointments'] ?? 0) ?></strong>
      <span>Today's Appointments</span>
    </div>
    <div class="prov-dash-stat prov-dash-stat--warn">
      <span class="prov-dash-stat__icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
      </span>
      <strong><?= (int) ($stats['pending'] ?? 0) ?></strong>
      <span>Waiting in Queue</span>
    </div>
    <div class="prov-dash-stat">
      <span class="prov-dash-stat__icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      </span>
      <strong><?= (int) ($stats['ongoing'] ?? 0) ?></strong>
      <span>In Consultation</span>
    </div>
    <div class="prov-dash-stat">
      <span class="prov-dash-stat__icon" aria-hidden="true">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </span>
      <strong><?= (int) ($stats['completed'] ?? 0) ?></strong>
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
          <span class="mc-badge"><?= $week_total ?> this week</span>
        </div>
        <p class="prov-dash-card__sub">Daily consultations over the last 7 days</p>
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

      <!-- Queue -->
      <section class="prov-dash-card prov-dash-table">
        <div class="prov-dash-card__head">
          <h3 class="prov-dash-card__title"><?= icon('users') ?> Upcoming Consultations</h3>
          <span class="mc-badge"><?= count($queue) ?> pending</span>
        </div>
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
            <strong><?= (int) ($stats['pending'] ?? 0) ?></strong>
          </div>
          <div class="prov-status-item">
            <span class="prov-status-item__label">
              <span class="prov-status-dot" style="background:#3b82f6;"></span>
              In Consultation
            </span>
            <strong><?= (int) ($stats['ongoing'] ?? 0) ?></strong>
          </div>
          <div class="prov-status-item">
            <span class="prov-status-item__label">
              <span class="prov-status-dot" style="background:#22c55e;"></span>
              Completed (month)
            </span>
            <strong><?= (int) ($stats['completed'] ?? 0) ?></strong>
          </div>
          <?php if (!empty($stats['urgent'])): ?>
          <div class="prov-status-item" style="border-color:#fecaca;background:#fef2f2;">
            <span class="prov-status-item__label">
              <span class="prov-status-dot" style="background:#ef4444;"></span>
              Urgent Triage
            </span>
            <strong style="color:#dc2626;"><?= (int) $stats['urgent'] ?></strong>
          </div>
          <?php endif; ?>
        </div>
      </section>

      <section class="prov-dash-card">
        <div class="prov-dash-card__head">
          <h3 class="prov-dash-card__title"><?= icon('activity') ?> Recent Activity</h3>
        </div>
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
      </section>

      <section class="prov-dash-card">
        <div class="prov-dash-card__head">
          <h3 class="prov-dash-card__title"><?= icon('video') ?> Session Recordings</h3>
        </div>
        <?php if (empty($recordings)): ?>
        <p class="text-xs text-muted" style="text-align:center;padding:12px 0;margin:0;">No recordings yet.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <?php foreach ($recordings as $rec): ?>
          <div class="prov-recording-item">
            <div>
              <div class="prov-recording-item__name"><?= htmlspecialchars(trim($rec['first_name'] . ' ' . $rec['last_name'])) ?></div>
              <div class="prov-recording-item__date"><?= date('M j, Y g:i A', strtotime($rec['ended_at'])) ?></div>
            </div>
            <div class="prov-recording-actions">
              <a href="<?= ASSET_BASE ?>/<?= htmlspecialchars($rec['recording_url']) ?>" target="_blank" rel="noopener" class="mc-btn mc-btn--outline" style="padding:4px 8px;font-size:10px;">View</a>
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
<?php require __DIR__.'/partials/layout_close.php'; ?>
