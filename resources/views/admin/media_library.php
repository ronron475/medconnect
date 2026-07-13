<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/auth_guard.php';
require_once BASE_PATH . '/app/includes/announcement_service.php';
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Media Library';
AnnouncementService::ensureSchema($pdo);
$media = AnnouncementService::listMedia($pdo);
$mediaApi = ASSET_BASE . '/app/api/admin/media_library.php';

$imageCount = count(array_filter($media, fn($m) => ($m['file_type'] ?? '') === 'image'));
$docCount = count($media) - $imageCount;

$cssVer = (int) @filemtime(ASSETS_PATH . '/css/admin-media-library.css');
$jsVer = (int) @filemtime(ASSETS_PATH . '/js/admin-media-library.js');

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-media-library.css?v=<?= $cssVer ?>">

<div class="ml-mgmt" id="mlMgmt" data-api="<?= htmlspecialchars($mediaApi) ?>" data-media="<?= htmlspecialchars(json_encode($media, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">

  <div class="ml-mgmt__header">
    <div>
      <h2 class="text-h2" style="margin:0 0 6px;">Media Library</h2>
      <p class="text-muted" style="margin:0;">Upload and manage images and PDFs for announcements and website content.</p>
    </div>
    <button type="button" class="mc-btn mc-btn--primary" id="mlToggleUpload">+ Upload file</button>
  </div>

  <div class="ml-mgmt__stats">
    <div class="mc-card" style="padding:14px 16px;">
      <div class="ml-mgmt__stat-value" id="mlStatTotal"><?= count($media) ?></div>
      <div class="ml-mgmt__stat-label">Total files</div>
    </div>
    <div class="mc-card" style="padding:14px 16px;">
      <div class="ml-mgmt__stat-value" id="mlStatImages"><?= $imageCount ?></div>
      <div class="ml-mgmt__stat-label">Images</div>
    </div>
    <div class="mc-card" style="padding:14px 16px;">
      <div class="ml-mgmt__stat-value" id="mlStatDocs"><?= $docCount ?></div>
      <div class="ml-mgmt__stat-label">Documents</div>
    </div>
  </div>

  <div class="mc-card ml-mgmt__upload-panel" id="mlUploadPanel">
    <form id="mlUploadForm" class="ml-mgmt__upload-form" enctype="multipart/form-data">
      <p class="text-muted" style="margin:0;font-size:13px;">JPEG, PNG, WebP, GIF, or PDF — max 5 MB.</p>
      <div class="ml-mgmt__upload-row">
        <div class="ml-mgmt__field" style="flex:1 1 220px;">
          <label for="mlFileInput">File</label>
          <input type="file" id="mlFileInput" name="file" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf" required>
        </div>
        <div class="ml-mgmt__field" style="flex:1 1 220px;">
          <label for="mlUploadAlt">Alt text <span class="text-muted">(optional)</span></label>
          <input type="text" id="mlUploadAlt" name="alt_text" maxlength="255" placeholder="Describe the image for accessibility">
        </div>
        <button type="submit" class="mc-btn mc-btn--primary" id="mlUploadSubmit">Upload file</button>
      </div>
    </form>
  </div>

  <div class="ml-mgmt__toolbar">
    <div class="ml-mgmt__filters">
      <button type="button" class="ml-mgmt__filter is-active" data-filter="all">All</button>
      <button type="button" class="ml-mgmt__filter" data-filter="image">Images</button>
      <button type="button" class="ml-mgmt__filter" data-filter="document">Documents</button>
    </div>
    <input type="search" class="ml-mgmt__search" id="mlSearch" placeholder="Search by filename or alt text…" aria-label="Search media">
  </div>

  <div class="ml-mgmt__grid" id="mlGrid"></div>

  <div class="ml-mgmt__toast" id="mlToast" role="status" aria-live="polite"></div>

  <div class="ml-mgmt__modal-backdrop" id="mlAltModal">
    <div class="ml-mgmt__modal" role="dialog" aria-labelledby="mlAltTitle">
      <h3 id="mlAltTitle">Edit alt text</h3>
      <div class="ml-mgmt__field">
        <label for="mlAltInput">Alt text</label>
        <input type="text" id="mlAltInput" maxlength="255" placeholder="Accessibility description">
      </div>
      <div class="ml-mgmt__modal-foot">
        <button type="button" class="mc-btn mc-btn--outline" id="mlAltCancel">Cancel</button>
        <button type="button" class="mc-btn mc-btn--primary" id="mlAltSave">Save</button>
      </div>
    </div>
  </div>

  <div class="ml-mgmt__modal-backdrop" id="mlDeleteModal">
    <div class="ml-mgmt__modal" role="dialog" aria-labelledby="mlDeleteTitle">
      <h3 id="mlDeleteTitle">Delete file?</h3>
      <p class="text-muted" style="margin:0;">Remove <strong id="mlDeleteName">this file</strong> from the library? This cannot be undone.</p>
      <div class="ml-mgmt__modal-foot">
        <button type="button" class="mc-btn mc-btn--outline" id="mlDeleteCancel">Cancel</button>
        <button type="button" class="mc-btn mc-btn--primary" id="mlDeleteConfirm" style="background:#b91c1c;border-color:#b91c1c;">Delete</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= ASSET_BASE ?>/assets/js/admin-media-library.js?v=<?= $jsVer ?>"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
