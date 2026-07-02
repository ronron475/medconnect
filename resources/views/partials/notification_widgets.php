<?php
/**
 * Dashboard notification widgets — include on role dashboards.
 *
 * $notif_widget_mode: 'full' | 'strip' | 'recent'
 */
$notif_widget_mode = $notif_widget_mode ?? 'full';
$notif_widget_exclude = $notif_widget_exclude ?? [];
$notif_widget_bare = !empty($notif_widget_bare);

$notif_widget_defs = [
    'unread_count' => ["Unread", ''],
    'today_appointments' => ["Today's Appointments", ''],
    'upcoming_consultations' => ['Upcoming Consultations', ''],
    'pending_referrals' => ['Pending Referrals', ''],
    'emergency_alerts' => ['Emergency Alerts', ' mc-notif-widget--alert'],
];

$notif_widget_render_item = static function (string $widget_key, string $widget_label, string $widget_class): void {
    ?>
  <div class="mc-notif-widget<?= $widget_class ?>" data-widget="<?= htmlspecialchars($widget_key) ?>">
    <div class="mc-notif-widget-value">0</div>
    <div class="mc-notif-widget-label"><?= htmlspecialchars($widget_label) ?></div>
  </div>
    <?php
};
?>

<?php if ($notif_widget_mode === 'full' || $notif_widget_mode === 'strip'): ?>
<?php if (!$notif_widget_bare): ?>
<div class="mc-notif-widgets mc-notif-widgets--dashboard" data-notif-widgets aria-label="Live operations summary">
<?php endif; ?>
  <?php foreach ($notif_widget_defs as $widget_key => [$widget_label, $widget_class]):
    if (in_array($widget_key, $notif_widget_exclude, true)) {
        continue;
    }
    $notif_widget_render_item($widget_key, $widget_label, $widget_class);
  endforeach; ?>
<?php if (!$notif_widget_bare): ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($notif_widget_mode === 'full' || $notif_widget_mode === 'recent'): ?>
<?php $notif_widget_skin = $notif_widget_skin ?? 'default'; ?>
<?php if ($notif_widget_skin === 'provider'): ?>
<section class="mc-notif-recent-panel" data-notif-widgets aria-label="Recent notifications">
  <header class="mc-notif-recent-panel__head">
    <div class="mc-notif-recent-panel__titles">
      <h3 class="mc-notif-recent-panel__title">Recent Notifications</h3>
      <p class="mc-notif-recent-panel__sub">Latest platform activity</p>
    </div>
    <a href="<?= ASSET_BASE ?>/views/notifications/index.php" class="mc-notif-recent-panel__link">View all</a>
  </header>
  <div class="mc-notif-recent-panel__body" data-widget="recent">
    <div class="mc-notif-list mc-notif-list--feed" data-notif-list>
      <div class="mc-notif-empty">Loading notifications…</div>
    </div>
  </div>
</section>
<?php else: ?>
<div class="adm-card adm-notif-recent" data-notif-widgets>
  <div class="adm-card-head">
    <div class="adm-card-head-icon adm-card-head-icon--blue">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </div>
    <div>
      <div class="adm-card-head-title">Recent Notifications</div>
      <div class="adm-card-head-sub">Latest platform activity</div>
    </div>
    <a href="<?= ASSET_BASE ?>/views/notifications/index.php" class="adm-card-head-action">View all</a>
  </div>
  <div class="adm-card-body" data-widget="recent">
    <div class="mc-notif-list adm-notif-list-compact" data-notif-list>
      <div class="mc-notif-empty">Loading notifications…</div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>
