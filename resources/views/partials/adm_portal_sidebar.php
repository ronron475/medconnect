<?php
/**
 * Unified admin-style portal sidebar — Admin, Super Admin, and BHW.
 *
 * Set $adm_sidebar_portal to 'admin', 'superadmin', or 'bhw' before including.
 * Navigation content is portal-specific; visual design is shared.
 */
declare(strict_types=1);

require_once BASE_PATH . '/app/includes/portal_paths.php';
require_once BASE_PATH . '/app/includes/nav/portal_nav_helpers.php';
require_once BASE_PATH . '/app/includes/profile_picture.php';

$adm_sidebar_portal = $adm_sidebar_portal ?? (
    (defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin') ? 'superadmin' : 'admin'
);

$portal_configs = [
    'admin' => [
        'nav_file' => 'admin_nav.php',
        'views_segment' => 'admin',
        'logo_em' => 'System Administrator',
        'profile_href' => ASSET_BASE . '/views/admin/profile.php',
        'profile_title' => 'Administrator profile settings',
        'profile_role' => 'System Administrator',
        'sidebar_class' => 'adm-sidebar',
        'default_name' => 'Admin',
        'default_initials' => ['S', 'A'],
        'aria_label' => 'System Administrator navigation',
    ],
    'superadmin' => [
        'nav_file' => 'superadmin_nav.php',
        'views_segment' => 'superadmin',
        'logo_em' => 'Super Administrator',
        'profile_href' => ASSET_BASE . '/views/superadmin/profile.php',
        'profile_title' => 'Super Administrator profile',
        'profile_role' => 'Super Administrator',
        'sidebar_class' => 'adm-sidebar adm-sidebar--superadmin',
        'default_name' => 'Super Admin',
        'default_initials' => ['S', 'A'],
        'aria_label' => 'Super Administrator navigation',
    ],
    'bhw' => [
        'nav_file' => null,
        'views_segment' => 'bhw',
        'logo_em' => 'BHW',
        'profile_href' => ASSET_BASE . '/views/bhw/settings/profile.php',
        'profile_title' => 'BHW profile settings',
        'profile_role' => 'BHW',
        'sidebar_class' => 'adm-sidebar adm-sidebar--bhw',
        'default_name' => 'Barangay Health Worker',
        'default_initials' => ['B', 'H'],
        'aria_label' => 'BHW panel navigation',
    ],
];

$config = $portal_configs[$adm_sidebar_portal] ?? $portal_configs['admin'];
$views_base = ASSET_BASE . '/views/' . $config['views_segment'];
$dashboard_href = $views_base . '/dashboard.php';

$display_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if ($display_name === '') {
    $display_name = $_SESSION['user_name'] ?? $config['default_name'];
}
$display_initials = profile_picture_initials(
    $_SESSION['first_name'] ?? $config['default_initials'][0],
    $_SESSION['last_name'] ?? $config['default_initials'][1]
);
$display_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);

$profile_role = $config['profile_role'];
if ($adm_sidebar_portal === 'bhw') {
    $barangay_name = trim((string) ($_SESSION['user_barangay_name'] ?? ''));
    if ($barangay_name === '') {
        $barangay_name = 'Unassigned';
    }
    $profile_role = 'BHW · Brgy. ' . $barangay_name;
}

if ($adm_sidebar_portal === 'bhw') {
    require_once BASE_PATH . '/app/includes/nav/bhw_nav.php';
    $nav_sections = bhw_nav_sections();
    $current = portal_nav_current_portal_path('bhw');
    $current_query = '';
    $is_profile_page = portal_nav_bhw_is_active('settings/profile.php', $current);
} else {
    $nav_sections = require BASE_PATH . '/app/includes/nav/' . $config['nav_file'];
    $current = portal_nav_current_basename();
    $current_query = portal_nav_current_query();
    $is_profile_page = ($current === 'profile.php');
}

require_once BASE_PATH . '/app/includes/portal_nav_badge_counts.php';
$portal_nav_badge_counts_data = [];
if (!empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $portal_nav_badge_counts_data = portal_nav_badge_counts($pdo, $adm_sidebar_portal, (int) $_SESSION['user_id']);
    } catch (Throwable $e) {
        $portal_nav_badge_counts_data = [];
    }
}
?>
<aside class="<?= htmlspecialchars($config['sidebar_class']) ?>"<?= $adm_sidebar_portal === 'bhw' ? ' id="bhw-sidebar"' : '' ?> aria-label="<?= htmlspecialchars($config['aria_label']) ?>">

  <a href="<?= htmlspecialchars($dashboard_href) ?>" class="adm-logo">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="medConnect" style="height: 35px; width: auto; object-fit: contain; margin-right: 10px;">
    <div class="adm-logo-text">med<span>Connect</span><em><?= htmlspecialchars($config['logo_em']) ?></em></div>
  </a>

  <nav class="adm-nav" data-portal-nav="<?= htmlspecialchars($adm_sidebar_portal) ?>" aria-label="<?= htmlspecialchars($config['aria_label']) ?>">
    <?php foreach ($nav_sections as $section):
      if (!empty($section['section'])): ?>
    <div class="adm-nav-section" style="padding: 12px 16px 4px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.45);">
      <?= htmlspecialchars($section['section']) ?>
    </div>
    <?php endif;
      foreach ($section['items'] as $item):
        [$file, $label, $icon_path] = $item;
        $itemQuery = $item[3] ?? null;
        $navGroup = $item[4] ?? null;
        $href = $views_base . '/' . $file . ($itemQuery ? '?' . $itemQuery : '');
        if ($adm_sidebar_portal === 'bhw') {
            $is_active = portal_nav_bhw_is_active((string) $file, $current);
        } else {
            $is_active = portal_nav_is_active($file, $current, $current_query, $itemQuery, $navGroup);
        }
        $badgeKey = portal_nav_badge_key_for_item($adm_sidebar_portal, $file, $itemQuery);
        $navAttr = portal_nav_badge_nav_link_attr($badgeKey);
    ?>
    <a href="<?= htmlspecialchars($href) ?>"
       class="adm-nav-item <?= $is_active ? 'is-active' : '' ?>"
       <?= $is_active ? 'aria-current="page"' : '' ?><?= $navAttr ?>>
      <svg class="adm-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $icon_path ?>
      </svg>
      <span class="adm-label"><?= htmlspecialchars($label) ?></span>
      <?php if ($badgeKey !== null):
          $portal_nav_badge_key = $badgeKey;
          $portal_nav_badge_count = portal_nav_badge_count_for_item($adm_sidebar_portal, $file, $itemQuery, $portal_nav_badge_counts_data);
          require VIEWS_PATH . '/partials/portal_nav_badge.php';
      endif; ?>
    </a>
    <?php endforeach; endforeach; ?>
  </nav>

  <a href="<?= htmlspecialchars($config['profile_href']) ?>"
     class="adm-profile <?= $is_profile_page ? 'is-active' : '' ?>"
     title="<?= htmlspecialchars($config['profile_title']) ?>">
    <div class="adm-profile-avatar" data-profile-avatar-wrap>
      <?= profile_picture_render($display_initials, $display_picture_url, '', 'sm') ?>
    </div>
    <div class="adm-profile-info">
      <?php if ($adm_sidebar_portal === 'bhw'): ?>
      <div class="adm-profile-name"><?= htmlspecialchars($display_name) ?></div>
      <div class="adm-profile-role"><?= htmlspecialchars($profile_role) ?></div>
      <?php else: ?>
      <div class="adm-profile-name"><?= htmlspecialchars($profile_role) ?></div>
      <?php endif; ?>
    </div>
  </a>

  <button type="button" class="adm-logout" data-logout-trigger aria-label="Sign out">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
    <span class="adm-label">Logout</span>
  </button>

</aside>
