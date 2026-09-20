<?php /* $page_title must be set before including */ ?>
<header class="pd-header">
  <button class="pd-hamburger" id="pdHamburger" type="button" aria-label="Open navigation menu" aria-expanded="false" aria-controls="app-sidebar">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
  </button>
  <div class="pd-header-title">
    <div class="pd-header-date"><?= htmlspecialchars(provider_format_date(date('Y-m-d'))) ?></div>
    <div class="pd-header-page"><?= htmlspecialchars($page_title ?? 'Dashboard') ?></div>
  </div>
  <div class="pd-header-right">
    <?php require_once VIEWS_PATH . '/partials/theme_toggle.php'; ?>
    <?php
    $fullscreen_btn_class = 'pd-notif-btn';
    require_once VIEWS_PATH . '/partials/fullscreen_toggle.php';
    ?>
    <div class="pd-header-clock" id="pdClock"></div>
    <?php
    $bell_class = 'pd-notif-btn mc-notif-btn';
    require_once VIEWS_PATH . '/partials/notification_bell.php';
    ?>
  </div>
</header>
