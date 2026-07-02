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
        'medical_records.php' => ['patients.php', 'records.php', 'consultation_history.php', 'patient_files.php'],
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
    <a href="<?= htmlspecialchars($provider_base, ENT_QUOTES) ?>/<?= htmlspecialchars($file) ?>"
       class="<?= $item_class ?>"<?= $is_active ? ' aria-current="page"' : '' ?>>
      <svg class="sba-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <?= $icon_path ?>
      </svg>
      <?= htmlspecialchars($label) ?>
    </a>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <?php if (!empty($_SESSION['user_id'])): ?>
  <a href="<?= htmlspecialchars($provider_base, ENT_QUOTES) ?>/settings.php" class="sba-profile" title="Profile Settings" style="text-decoration:none;color:inherit;display:block;">
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
    Sign Out
  </button>

</aside>

<div id="logout-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="logout-modal-title"
     style="display:none; position:fixed; inset:0; z-index:9999;
            background:rgba(0,0,0,0.55); backdrop-filter:blur(4px);
            align-items:center; justify-content:center;">
  <div id="logout-modal-box"
       style="background:#0d1b2a; border:1px solid rgba(2,128,144,0.35);
              border-radius:16px; padding:36px 40px; width:360px; max-width:90vw;
              box-shadow:0 24px 60px rgba(0,0,0,0.6);
              transform:scale(0.92); opacity:0;
              transition:transform .22s cubic-bezier(.34,1.56,.64,1), opacity .18s ease;">
    <div style="display:flex; justify-content:center; margin-bottom:18px;">
      <div style="width:56px; height:56px; border-radius:50%;
                  background:rgba(2,128,144,0.15); border:2px solid rgba(2,128,144,0.4);
                  display:flex; align-items:center; justify-content:center;">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#028090"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
          <polyline points="16 17 21 12 16 7"/>
          <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
      </div>
    </div>
    <h2 id="logout-modal-title" style="margin:0 0 8px; text-align:center; font-size:1.15rem; font-weight:700; color:#e8f4f6;">Confirm Logout</h2>
    <p style="margin:0 0 28px; text-align:center; color:#8bb4bc; font-size:0.9rem; line-height:1.55;">
      Are you sure you want to log out?<br>Any unsaved changes will be lost.
    </p>
    <div style="display:flex; gap:12px;">
      <button id="logout-modal-no" type="button" onclick="hideLogoutModal()"
              style="flex:1; padding:11px 0; border-radius:10px; border:1px solid rgba(2,128,144,0.4);
                     background:transparent; color:#028090; font-size:0.9rem; font-weight:600; cursor:pointer;">No, Stay</button>
      <button id="logout-modal-yes" type="button" data-logout-confirm
              style="flex:1; padding:11px 0; border-radius:10px; border:none;
                     background:linear-gradient(135deg,#028090,#015f6b); color:#fff; font-size:0.9rem; font-weight:600; cursor:pointer;">Yes, Log Out</button>
    </div>
  </div>
</div>

<script>
(function () {
  var overlay = document.getElementById('logout-modal-overlay');
  var box     = document.getElementById('logout-modal-box');
  window.showLogoutModal = function () {
    overlay.style.display = 'flex';
    requestAnimationFrame(function () { requestAnimationFrame(function () { box.style.transform = 'scale(1)'; box.style.opacity = '1'; }); });
  };
  window.hideLogoutModal = function () {
    box.style.transform = 'scale(0.92)'; box.style.opacity = '0';
    setTimeout(function () { overlay.style.display = 'none'; }, 200);
  };
  overlay.addEventListener('click', function (e) { if (e.target === overlay) hideLogoutModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.style.display === 'flex') hideLogoutModal(); });
  if (window.MedConnectLogout && typeof window.MedConnectLogout.init === 'function') {
    window.MedConnectLogout.init();
  }
})();
</script>
