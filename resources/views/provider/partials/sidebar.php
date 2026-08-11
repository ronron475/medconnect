<?php
$current_page = basename($_SERVER['PHP_SELF'] ?? '');
$current_path = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
$request_path = str_replace('\\', '/', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$active_page  = $active_page ?? str_replace('.php', '', $current_page);
$provider_base = ASSET_BASE . '/views/provider';

require_once BASE_PATH . '/app/includes/profile_picture.php';

$initials = profile_picture_initials($_SESSION['first_name'] ?? 'D', $_SESSION['last_name'] ?? '');
$sidebar_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);
$full_name = trim('Dr. ' . ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

$user_role = (string) ($_SESSION['user_role'] ?? '');
$nav_groups = require BASE_PATH . '/app/includes/nav/provider_nav.php';

require_once BASE_PATH . '/app/includes/portal_nav_badge_counts.php';
$portal_nav_badge_counts_data = [];
if ($user_role === 'provider' && !empty($_SESSION['user_id']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $portal_nav_badge_counts_data = portal_nav_badge_counts($pdo, 'provider', (int) $_SESSION['user_id']);
    } catch (Throwable $e) {
        $portal_nav_badge_counts_data = [];
    }
}

function provider_nav_is_active(string $file, string $current_page, string $current_path, string $request_path, string $active_page): bool
{
    if ($current_page === $file || $active_page . '.php' === $file) {
        return true;
    }

    $suffix = '/views/provider/' . $file;
    if (str_ends_with($current_path, $suffix) || str_ends_with($request_path, $suffix)) {
        return true;
    }

    // Legacy aliases that redirect into another nav destination.
    $aliases = [
        'medical_records.php' => ['patients.php', 'records.php', 'patient_files.php'],
        'triage.php' => ['triage_history.php'],
    ];
    foreach ($aliases[$file] ?? [] as $alias) {
        $alias_suffix = '/views/provider/' . $alias;
        if (str_ends_with($current_path, $alias_suffix) || str_ends_with($request_path, $alias_suffix)) {
            return true;
        }
        if ($active_page . '.php' === $alias || $active_page === str_replace('.php', '', $alias)) {
            return true;
        }
    }

    return false;
}
?>
<aside class="sb-aqua sidebar">

  <a href="<?= htmlspecialchars($provider_base, ENT_QUOTES) ?>/dashboard.php" class="sba-logo">
    <img src="<?= ASSET_BASE ?>/assets/img/medcon_logo.png" alt="medConnect" class="sba-logo-img">
  </a>

  <nav class="sba-nav">
    <?php foreach ($nav_groups as $group):
      if ($user_role !== 'provider') continue;
      $group_secondary = !empty($group['secondary']);
    ?>
    <?php if (!empty($group['label'])): ?>
    <div class="sba-group-label<?= $group_secondary ? ' sba-group-label--secondary' : '' ?>"><?= htmlspecialchars($group['label']) ?></div>
    <?php endif; ?>
    <?php foreach ($group['items'] as [$file, $label, $icon_path]):
      $is_active = provider_nav_is_active($file, $current_page, $current_path, $request_path, $active_page);
      $item_class = 'sba-item' . ($is_active ? ' is-active' : '') . ($group_secondary ? ' sba-item--secondary' : '');
    ?>
    <?php
      $badgeKey = portal_nav_badge_key_for_item('provider', $file, null);
      $navAttr = portal_nav_badge_nav_link_attr($badgeKey);
    ?>
    <a href="<?= htmlspecialchars($provider_base, ENT_QUOTES) ?>/<?= htmlspecialchars($file) ?>"
       class="<?= $item_class ?>"<?= $is_active ? ' aria-current="page"' : '' ?><?= $navAttr ?>>
      <svg class="sba-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $icon_path ?>
      </svg>
      <span class="sba-label"><?= htmlspecialchars($label) ?></span>
      <?php if ($badgeKey !== null):
          $portal_nav_badge_key = $badgeKey;
          $portal_nav_badge_count = portal_nav_badge_count_for_item('provider', $file, null, $portal_nav_badge_counts_data);
          require VIEWS_PATH . '/partials/portal_nav_badge.php';
      endif; ?>
    </a>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <?php if (!empty($_SESSION['user_id'])): ?>
  <a href="<?= htmlspecialchars($provider_base, ENT_QUOTES) ?>/settings.php" class="sba-profile <?= provider_nav_is_active('settings.php', $current_page, $current_path, $request_path, $active_page) ? 'is-active' : '' ?>" title="Profile Settings" style="text-decoration:none;color:inherit;display:block;">
    <div style="display:flex;align-items:center;gap:12px;">
      <div class="sba-avatar" data-profile-avatar-wrap><?= profile_picture_render($initials, $sidebar_picture_url, '', 'sm') ?></div>
      <div class="sba-profile-info">
        <div class="sba-name"><?= htmlspecialchars($full_name) ?></div>
        <div class="sba-role">
          <?= htmlspecialchars($provider['facility'] ?? $_SESSION['user_sector'] ?? 'City Health Office') ?>
        </div>
      </div>
    </div>
  </a>
  <?php endif; ?>

  <button type="button" class="sba-logout" data-logout-trigger>
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>
    <span class="sba-label">Sign Out</span>
  </button>

</aside>
