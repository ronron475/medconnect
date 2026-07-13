<?php
require_once BASE_PATH . '/app/includes/portal_paths.php';
$current = portal_current_view_basename();
$current_query = $_SERVER['QUERY_STRING'] ?? '';

require_once BASE_PATH . '/app/includes/profile_picture.php';

$admin_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if (!$admin_name) $admin_name = $_SESSION['user_name'] ?? 'Super Admin';
$admin_initials = profile_picture_initials($_SESSION['first_name'] ?? 'S', $_SESSION['last_name'] ?? 'A');
$admin_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);

$nav_sections = require BASE_PATH . '/app/includes/nav/superadmin_nav.php';

function superadmin_nav_is_active(string $file, string $current, string $query, ?string $itemQuery): bool
{
    if ($current !== $file) return false;
    if ($itemQuery === null || $itemQuery === '') {
        return $query === '' || !str_contains($query, 'role=');
    }
    parse_str($itemQuery, $expected);
    parse_str($query, $actual);
    foreach ($expected as $k => $v) {
        if (($actual[$k] ?? '') !== $v) return false;
    }
    return true;
}
?>
<aside class="adm-sidebar adm-sidebar--superadmin">

  <a href="<?= ASSET_BASE ?>/views/superadmin/dashboard.php" class="adm-logo">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="medConnect" style="height: 35px; width: auto; object-fit: contain; margin-right: 10px;">
    <div class="adm-logo-text">med<span>Connect</span><em>Super</em></div>
  </a>

  <nav class="adm-nav">
    <?php foreach ($nav_sections as $section):
      if (!empty($section['section'])): ?>
    <div class="adm-nav-section" style="padding: 12px 16px 4px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(255,255,255,0.45);">
      <?= htmlspecialchars($section['section']) ?>
    </div>
    <?php endif;
      foreach ($section['items'] as $item):
        [$file, $label, $icon_path] = $item;
        $itemQuery = $item[3] ?? null;
        $href = ASSET_BASE . '/views/superadmin/' . $file . ($itemQuery ? '?' . $itemQuery : '');
        $is_active = superadmin_nav_is_active($file, $current, $current_query, $itemQuery);
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="adm-nav-item <?= $is_active ? 'is-active' : '' ?>">
      <svg class="adm-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $icon_path ?>
      </svg>
      <span class="adm-label"><?= htmlspecialchars($label) ?></span>
    </a>
    <?php endforeach; endforeach; ?>
  </nav>

  <a href="<?= ASSET_BASE ?>/views/superadmin/profile.php" class="adm-profile" title="Super Administrator profile">
    <div class="adm-profile-avatar" data-profile-avatar-wrap>
      <?= profile_picture_render($admin_initials, $admin_picture_url, '', 'sm') ?>
    </div>
    <div class="adm-profile-info">
      <div class="adm-profile-name"><?= htmlspecialchars($admin_name) ?></div>
      <div class="adm-profile-role">Super Administrator</div>
    </div>
  </a>

  <button type="button" class="adm-logout" data-logout-trigger>
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
    <span class="adm-label">Sign Out</span>
  </button>

</aside>
