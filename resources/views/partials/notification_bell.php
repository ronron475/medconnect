<?php
/**
 * Shared notification bell + dropdown panel
 * Include in header partials for all authenticated roles.
 */
$notifPageUrl = ASSET_BASE . '/views/notifications/index.php';
if (!empty($_SESSION['user_role'])) {
    $rolePages = [
        'admin'       => ASSET_BASE . '/views/notifications/index.php',
        'superadmin'  => ASSET_BASE . '/views/notifications/index.php',
        'provider' => ASSET_BASE . '/views/notifications/index.php',
        'patient'  => ASSET_BASE . '/views/notifications/index.php',
        'bhw'      => ASSET_BASE . '/views/notifications/index.php',
    ];
    $notifPageUrl = $rolePages[$_SESSION['user_role']] ?? $notifPageUrl;
}
$bellClass = $bell_class ?? 'topbar-icon-btn';
$wrapClass = $wrap_class ?? 'mc-notif-wrap';
?>
<div class="<?= htmlspecialchars($wrapClass) ?>" data-notif-wrap>
  <button type="button"
          class="<?= htmlspecialchars($bellClass) ?> mc-notif-btn"
          data-notif-toggle
          aria-label="Notifications"
          aria-expanded="false"
          aria-haspopup="true"
          aria-controls="mcNotifPanel">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
    </svg>
    <span class="mc-notif-badge" data-notif-badge data-count="0" aria-live="polite"></span>
  </button>

  <div class="mc-notif-panel" id="mcNotifPanel" data-notif-panel role="menu" aria-label="Notification center">
    <div class="mc-notif-panel-header">
      <h2>Notifications</h2>
      <div class="mc-notif-panel-actions">
        <button type="button" data-notif-mark-all aria-label="Mark all notifications as read">Mark all read</button>
      </div>
    </div>
    <div class="mc-notif-list" data-notif-list role="group" aria-label="Recent notifications">
      <div class="mc-notif-empty">Loading…</div>
    </div>
    <div class="mc-notif-footer">
      <a href="<?= htmlspecialchars($notifPageUrl) ?>">View all notifications</a>
    </div>
  </div>
</div>
