<?php
/**
 * Profile picture upload card.
 *
 * Expected vars:
 * - string $profile_initials
 * - ?string $profile_picture_url
 * - string $profile_display_name
 * - string $profile_role_label
 */
require_once BASE_PATH . '/app/includes/profile_picture.php';
$profile_initials      = $profile_initials ?? 'U';
$profile_picture_url   = $profile_picture_url ?? null;
$profile_display_name  = $profile_display_name ?? 'User';
$profile_role_label    = $profile_role_label ?? '';
$profile_upload_layout = $profile_upload_layout ?? 'default';
$profile_upload_ver    = (int) filemtime(ASSETS_PATH . '/js/profile-picture.js');
$upload_layout_class   = $profile_upload_layout === 'portal' ? ' profile-picture-upload--portal' : '';
?>
<div
  class="profile-picture-upload<?= $upload_layout_class ?>"
  data-profile-upload
  data-upload-url="<?= htmlspecialchars(ASSET_BASE . '/app/api/profile/upload_picture.php') ?>"
  data-csrf="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"
>
  <div data-profile-preview>
    <?= profile_picture_render($profile_initials, $profile_picture_url, 'profile-avatar--xl') ?>
  </div>
  <div class="profile-picture-upload__meta">
    <h4><?= htmlspecialchars($profile_display_name) ?></h4>
    <?php if ($profile_role_label !== ''): ?>
    <p><?= htmlspecialchars($profile_role_label) ?></p>
    <?php endif; ?>
    <input type="file" accept="image/jpeg,image/png,image/webp">
    <button type="button" class="mc-btn mc-btn--outline" data-profile-trigger>Change Photo</button>
    <p class="profile-picture-status" data-profile-status>JPG, PNG, or WEBP up to 2 MB.</p>
  </div>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/profile-picture.js?v=<?= $profile_upload_ver ?>"></script>
