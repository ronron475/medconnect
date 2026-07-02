<?php
/**
 * BHW Panel — grouped sidebar navigation (matches Admin/Provider design system).
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
$dashboard = bhw_nav_dashboard();
$bhw_nav_groups = bhw_nav_groups();

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

function bhw_group_is_open(array $group, string $current_page, string $current_path, string $current_route = ''): bool
{
    foreach ($group['children'] as $child) {
        if (($child['type'] ?? '') === 'logout') {
            continue;
        }
        if (bhw_nav_is_active($child['file'], $current_page, $current_path, $current_route)) {
            return true;
        }
    }
    // Highlight group when on a legacy route mapped to this section
    $legacyPaths = [
        'triage' => ['triage/encode.php', 'appointments/book.php'],
        'consultations' => ['appointments/schedule.php', 'consultation/assist.php', 'consultation/status.php'],
    ];
    $groupId = $group['id'] ?? '';
    if (!empty($legacyPaths[$groupId])) {
        foreach ($legacyPaths[$groupId] as $legacy) {
            if ($legacy === $current_page
                || $legacy === $current_route
                || str_ends_with($current_path, '/views/bhw/' . $legacy)
                || str_ends_with($current_route, $legacy)) {
                return true;
            }
        }
    }
    return false;
}

$dashboard_active = bhw_nav_is_active('dashboard.php', $current_page, $current_path, $current_route);
?>
<aside class="bhw-sidebar" id="bhw-sidebar" aria-label="BHW navigation">

  <a href="<?= $bhw_base ?>/dashboard.php" class="bhw-sb-logo">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="medConnect" class="bhw-sb-logo-img">
    <div class="bhw-sb-logo-text">
      med<span>Connect</span>
      <em>BHW</em>
    </div>
  </a>

  <nav class="bhw-sb-nav" aria-label="BHW panel navigation">
    <a href="<?= $bhw_base ?>/dashboard.php"
       class="bhw-sb-item <?= $dashboard_active ? 'is-active' : '' ?>"
       title="<?= htmlspecialchars($dashboard['description']) ?>"
       <?= $dashboard_active ? 'aria-current="page"' : '' ?>>
      <?= bhw_nav_render_icon($dashboard['icon']) ?>
      <span class="bhw-sb-item-body">
        <span class="bhw-sb-item-label"><?= htmlspecialchars($dashboard['label']) ?></span>
        <span class="bhw-sb-item-desc"><?= htmlspecialchars($dashboard['description']) ?></span>
      </span>
    </a>

    <?php foreach ($bhw_nav_groups as $group):
        $group_id = $group['id'] ?? '';
        if ($group_id === 'patients'): ?>
    <div class="bhw-sb-nav-section">Barangay Operations</div>
        <?php elseif ($group_id === 'reports'): ?>
    <div class="bhw-sb-nav-section">Reports</div>
        <?php elseif ($group_id === 'settings'): ?>
    <div class="bhw-sb-nav-section">Account</div>
        <?php endif;
        $group_open = bhw_group_is_open($group, $current_page, $current_path, $current_route);
        $group_has_active = $group_open;
    ?>
    <div class="bhw-sb-group <?= $group_open ? 'is-open' : '' ?>" data-bhw-group="<?= htmlspecialchars($group['id']) ?>">
      <button type="button"
              class="bhw-sb-group-btn<?= $group_open ? ' is-expanded' : '' ?><?= $group_has_active ? ' has-active-child is-active' : '' ?>"
              aria-expanded="<?= $group_open ? 'true' : 'false' ?>"
              aria-controls="bhw-group-<?= htmlspecialchars($group['id']) ?>"
              title="<?= htmlspecialchars($group['description']) ?>">
        <?= bhw_nav_render_icon($group['icon']) ?>
        <span class="bhw-sb-item-body">
          <span class="bhw-sb-item-label"><?= htmlspecialchars($group['label']) ?></span>
          <span class="bhw-sb-item-desc"><?= htmlspecialchars($group['description']) ?></span>
        </span>
        <svg class="bhw-sb-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
          <polyline points="6 9 12 15 18 9"/>
        </svg>
      </button>
      <div class="bhw-sb-submenu" id="bhw-group-<?= htmlspecialchars($group['id']) ?>" role="group" aria-label="<?= htmlspecialchars($group['label']) ?>">
        <?php foreach ($group['children'] as $child): ?>
          <?php if (($child['type'] ?? '') === 'logout'): ?>
            <button type="button" class="bhw-sb-subitem bhw-sb-subitem--logout" data-logout-trigger>
              <?= htmlspecialchars($child['label']) ?>
            </button>
          <?php else:
            $child_active = bhw_nav_is_active($child['file'], $current_page, $current_path, $current_route);
          ?>
            <a href="<?= $bhw_base ?>/<?= htmlspecialchars($child['file']) ?>"
               class="bhw-sb-subitem <?= $child_active ? 'is-active' : '' ?>"
               data-bhw-route="<?= htmlspecialchars($child['file']) ?>"
               title="<?= htmlspecialchars($child['hint'] ?? $child['label']) ?>"
               <?= $child_active ? 'aria-current="page"' : '' ?>>
              <?php if (!empty($child['icon'])): ?>
                <?= bhw_nav_render_subitem_icon($child['icon']) ?>
              <?php endif; ?>
              <span class="bhw-sb-subitem-body">
                <span class="bhw-sb-subitem-label"><?= htmlspecialchars($child['label']) ?></span>
                <?php if (!empty($child['hint'])): ?>
                <span class="bhw-sb-subitem-hint"><?= htmlspecialchars($child['hint']) ?></span>
                <?php endif; ?>
              </span>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </nav>

  <div class="bhw-sb-footer">
  <?php if (!empty($_SESSION['user_id'])): ?>
  <a href="<?= $bhw_base ?>/settings/profile.php" class="bhw-sb-profile" title="BHW profile settings">
    <div class="bhw-sb-profile-avatar" data-profile-avatar-wrap>
      <?= profile_picture_render($initials, $sidebar_picture_url, '', 'sm') ?>
    </div>
    <div class="bhw-sb-profile-info">
      <div class="bhw-sb-profile-name"><?= htmlspecialchars($full_name ?: 'Barangay Health Worker') ?></div>
      <div class="bhw-sb-profile-role">Barangay Health Worker</div>
      <div class="bhw-sb-profile-sector">Brgy. <?= htmlspecialchars($barangay_name) ?></div>
      <span class="bhw-sb-profile-status">Active</span>
    </div>
  </a>
  <?php endif; ?>

  <button type="button" class="bhw-sb-logout" data-logout-trigger aria-label="Sign out of BHW panel">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
    Sign Out
  </button>
  </div>

</aside>

<?php require_once VIEWS_PATH . '/partials/logout_modal.php'; ?>
