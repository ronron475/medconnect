<?php
/**
 * BHW Panel — admin-style flat sidebar navigation.
 */
require_once __DIR__ . '/bhw_nav.php';
require_once BASE_PATH . '/app/includes/profile_picture.php';

$current_page = basename($_SERVER['PHP_SELF'] ?? '');
$current_path = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
$current_route = $current_page;

if ($current_page === 'view.php') {
    $route_path = str_replace('\\', '/', (string) ($_GET['path'] ?? ''));
    if (preg_match('#^bhw/(.+)$#', $route_path, $route_match)) {
        $current_route = $route_match[1];
        $current_page = basename($current_route);
    } elseif (preg_match('#/views/bhw/(.+?)(?:\?|#|$)#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $uri_match)) {
        $current_route = $uri_match[1];
        $current_page = basename($current_route);
    }
} elseif (preg_match('#/views/bhw/(.+?)(?:\?|#|$)#', (string) ($_SERVER['REQUEST_URI'] ?? ''), $uri_match)) {
    $current_route = $uri_match[1];
}

$bhw_base = ASSET_BASE . '/views/bhw';
$nav_sections = bhw_nav_sections();

$initials = profile_picture_initials($_SESSION['first_name'] ?? 'U', $_SESSION['last_name'] ?? '');
$sidebar_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);
$full_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$barangay_name = $_SESSION['user_barangay_name'] ?? 'Unassigned';

function bhw_nav_is_active(string $file, string $current_page, string $current_path, string $current_route = ''): bool
{
    $resolved = bhw_nav_resolve_file($file);
    $route = $current_route !== '' ? bhw_nav_resolve_file($current_route) : '';

    if ($route !== '' && ($route === $resolved || $route === $file)) {
        return true;
    }
    if ($resolved === $current_page) {
        return true;
    }
    if (str_ends_with($current_path, '/views/bhw/' . $resolved)) {
        return true;
    }
    if ($file === $current_page) {
        return true;
    }
    if (str_ends_with($current_path, '/views/bhw/' . $file)) {
        return true;
    }
    if ($current_route !== '' && ($current_route === $file || str_ends_with($current_route, '/' . $file))) {
        return true;
    }
    return false;
}
?>
<aside class="adm-sidebar adm-sidebar--bhw" id="bhw-sidebar" aria-label="BHW navigation">

  <a href="<?= $bhw_base ?>/dashboard.php" class="adm-logo">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="medConnect" style="height: 35px; width: auto; object-fit: contain; margin-right: 10px;">
    <div class="adm-logo-text">med<span>Connect</span><em>Operations</em></div>
  </a>

  <nav class="adm-nav" aria-label="BHW panel navigation">
    <?php foreach ($nav_sections as $section):
      if (!empty($section['section'])): ?>
    <div class="adm-nav-section" style="padding: 14px 16px 6px; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: rgba(255,255,255,0.38);">
      <?= htmlspecialchars($section['section']) ?>
    </div>
    <?php endif;
      foreach ($section['items'] as $item):
        [$file, $label, $icon_path] = $item;
        $href = $bhw_base . '/' . $file;
        $is_active = bhw_nav_is_active($file, $current_page, $current_path, $current_route);
    ?>
    <a href="<?= htmlspecialchars($href) ?>"
       class="adm-nav-item <?= $is_active ? 'is-active' : '' ?>"
       <?= $is_active ? 'aria-current="page"' : '' ?>>
      <svg class="adm-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $icon_path ?>
      </svg>
      <span class="adm-label"><?= htmlspecialchars($label) ?></span>
    </a>
    <?php endforeach; endforeach; ?>
  </nav>

  <a href="<?= $bhw_base ?>/settings/profile.php" class="adm-profile" title="BHW profile settings">
    <div class="adm-profile-avatar" data-profile-avatar-wrap>
      <?= profile_picture_render($initials, $sidebar_picture_url, '', 'sm') ?>
    </div>
    <div class="adm-profile-info">
      <div class="adm-profile-name"><?= htmlspecialchars($full_name ?: 'Barangay Health Worker') ?></div>
      <div class="adm-profile-role">Barangay Health Worker · Brgy. <?= htmlspecialchars($barangay_name) ?></div>
    </div>
  </a>

  <button type="button" class="adm-logout" data-logout-trigger aria-label="Sign out of BHW panel">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
    <span class="adm-label">Sign Out</span>
  </button>

</aside>

<?php require_once VIEWS_PATH . '/partials/logout_modal.php'; ?>
