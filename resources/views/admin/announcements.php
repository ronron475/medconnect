<?php
session_start();
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

$page_title = 'Announcement Management';
AnnouncementService::ensureSchema($pdo);

$authors = $pdo->query("
  SELECT DISTINCT u.id, CONCAT(u.first_name,' ',u.last_name) AS name
  FROM users u
  INNER JOIN announcements a ON a.author_id = u.id OR a.created_by = u.id
  WHERE u.role = 'admin'
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$apiBase = ASSET_BASE . '/app/api/admin/announcements.php';
$mediaApi = ASSET_BASE . '/app/api/admin/media_library.php';
$categories = AnnouncementService::CATEGORIES;
$audiences = AnnouncementService::AUDIENCES;
$barangays = AnnouncementService::listBarangays($pdo);

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-announcements.css?v=2">

<div class="ann-mgmt">
  <div class="ann-mgmt__header">
    <div>
      <h2 class="text-h2">Announcement Management</h2>
      <p class="text-muted">Create, publish, schedule, and manage announcements. Published items appear on the landing page automatically.</p>
    </div>
    <button type="button" class="mc-btn mc-btn--primary" id="annCreateBtn">+ Create Announcement</button>
  </div>

  <div class="mc-card ann-mgmt__filters">
    <div class="ann-mgmt__filter-grid">
      <input type="search" id="annSearch" placeholder="Search by title…" class="ann-input">
      <select id="annFilterStatus" class="ann-input">
        <option value="">All statuses</option>
        <?php foreach (AnnouncementService::STATUSES as $s): ?>
        <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="annFilterCategory" class="ann-input">
        <option value="">All categories</option>
        <?php foreach ($categories as $k => $label): ?>
        <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="annFilterAudience" class="ann-input">
        <option value="">All audiences</option>
        <?php foreach ($audiences as $k => $label): ?>
        <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="annFilterAuthor" class="ann-input">
        <option value="">All authors</option>
        <?php foreach ($authors as $a): ?>
        <option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="annFilterPinned" class="ann-input">
        <option value="">Pinned: any</option>
        <option value="1">Pinned only</option>
        <option value="0">Not pinned</option>
      </select>
      <input type="date" id="annFilterDateFrom" class="ann-input" title="Publish from">
      <input type="date" id="annFilterDateTo" class="ann-input" title="Publish to">
      <button type="button" class="mc-btn mc-btn--outline" id="annFilterReset">Reset</button>
    </div>
  </div>

  <div id="annSkeleton" class="ann-skeleton" hidden>
    <div class="ann-skeleton__row"></div>
    <div class="ann-skeleton__row"></div>
    <div class="ann-skeleton__row"></div>
  </div>

  <div class="mc-card ann-mgmt__table-wrap" id="annTableWrap" style="padding:0;overflow:hidden;">
    <table class="mc-table ann-mgmt__table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Category</th>
          <th>Audience</th>
          <th>Status</th>
          <th>Publish</th>
          <th>Views</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="annTableBody"></tbody>
    </table>
    <div id="annEmpty" class="mc-table-empty" hidden><p>No announcements match your filters.</p></div>
  </div>

  <p class="text-xs text-muted" id="annCount"></p>
</div>

<!-- Editor Modal -->
<div class="ann-modal" id="annEditorModal" hidden>
  <div class="ann-modal__backdrop" data-close-modal></div>
  <div class="ann-modal__panel" role="dialog" aria-labelledby="annModalTitle">
    <div class="ann-modal__head">
      <h3 id="annModalTitle">Create Announcement</h3>
      <button type="button" class="ann-modal__close" data-close-modal>&times;</button>
    </div>
    <form id="annForm" class="ann-modal__body">
      <input type="hidden" name="id" id="annId" value="">
      <div class="ann-form-grid">
        <div class="ann-form-col ann-form-col--wide">
          <label class="ann-label">Title *</label>
          <input type="text" name="title" id="annTitle" required class="ann-input" maxlength="255">
        </div>
        <div class="ann-form-col">
          <label class="ann-label">Subtitle</label>
          <input type="text" name="subtitle" id="annSubtitle" class="ann-input" maxlength="255">
        </div>
        <div class="ann-form-col">
          <label class="ann-label">Category</label>
          <select name="category" id="annCategory" class="ann-input">
            <?php foreach ($categories as $k => $label): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ann-form-col">
          <label class="ann-label">Priority</label>
          <select name="priority" id="annPriority" class="ann-input">
            <option value="low">Low</option>
            <option value="normal" selected>Normal</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select>
        </div>
        <div class="ann-form-col ann-form-col--full">
          <label class="ann-label">Short Description</label>
          <textarea name="short_description" id="annShortDesc" rows="2" class="ann-input" maxlength="500" placeholder="Shown on landing page cards"></textarea>
        </div>
        <div class="ann-form-col ann-form-col--full">
          <label class="ann-label">Full Content *</label>
          <textarea name="content" id="annContent" rows="6" required class="ann-input" placeholder="Full announcement body"></textarea>
        </div>
        <div class="ann-form-col">
          <label class="ann-label">Banner Image</label>
          <div class="ann-upload-row">
            <input type="hidden" name="banner_image" id="annBannerPath">
            <input type="file" id="annBannerFile" accept="image/*" class="ann-input">
            <button type="button" class="mc-btn mc-btn--outline mc-btn--sm" id="annPickBanner">Media Library</button>
          </div>
          <img id="annBannerPreview" class="ann-img-preview" alt="" hidden>
        </div>
        <div class="ann-form-col">
          <label class="ann-label">Attachment (PDF)</label>
          <div class="ann-upload-row">
            <input type="hidden" name="attachment" id="annAttachmentPath">
            <input type="file" id="annAttachmentFile" accept=".pdf,application/pdf" class="ann-input">
          </div>
          <span id="annAttachmentName" class="text-xs text-muted"></span>
        </div>
        <div class="ann-form-col ann-form-col--full">
          <label class="ann-label">Target Audience</label>
          <div class="ann-check-grid">
            <?php foreach ($audiences as $k => $label): ?>
            <label class="ann-check"><input type="checkbox" name="target_audience[]" value="<?= $k ?>"> <?= htmlspecialchars($label) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($barangays): ?>
        <div class="ann-form-col ann-form-col--full" id="annBarangayWrap">
          <label class="ann-label">Specific Barangays (optional, for BHW targeting)</label>
          <div class="ann-check-grid">
            <?php foreach ($barangays as $b): ?>
            <label class="ann-check"><input type="checkbox" name="barangay_ids[]" value="<?= (int)$b['id'] ?>"> <?= htmlspecialchars($b['name']) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="ann-form-col">
          <label class="ann-label">Publish Date</label>
          <input type="datetime-local" name="publish_at" id="annPublishAt" class="ann-input">
        </div>
        <div class="ann-form-col">
          <label class="ann-label">Expiration Date</label>
          <input type="datetime-local" name="expire_at" id="annExpireAt" class="ann-input">
        </div>
        <div class="ann-form-col ann-form-col--full ann-check-row">
          <label class="ann-check"><input type="checkbox" name="is_pinned" id="annPinned" value="1"> Pin announcement</label>
          <label class="ann-check"><input type="checkbox" name="is_featured" id="annFeatured" value="1"> Featured</label>
        </div>
      </div>
      <div class="ann-modal__foot">
        <button type="button" class="mc-btn mc-btn--outline" id="annPreviewBtn">Preview</button>
        <button type="submit" class="mc-btn mc-btn--outline" data-save-action="draft">Save Draft</button>
        <button type="submit" class="mc-btn mc-btn--outline" data-save-action="schedule">Schedule</button>
        <button type="submit" class="mc-btn mc-btn--primary" data-save-action="publish">Publish</button>
      </div>
    </form>
  </div>
</div>

<!-- Preview Modal -->
<div class="ann-modal" id="annPreviewModal" hidden>
  <div class="ann-modal__backdrop" data-close-preview></div>
  <div class="ann-modal__panel ann-modal__panel--preview" role="dialog">
    <div class="ann-modal__head">
      <h3>Preview</h3>
      <button type="button" class="ann-modal__close" data-close-preview>&times;</button>
    </div>
    <div class="ann-preview" id="annPreviewContent"></div>
  </div>
</div>

<!-- Media Picker -->
<div class="ann-modal" id="annMediaModal" hidden>
  <div class="ann-modal__backdrop" data-close-media></div>
  <div class="ann-modal__panel" role="dialog">
    <div class="ann-modal__head">
      <h3>Media Library</h3>
      <button type="button" class="ann-modal__close" data-close-media>&times;</button>
    </div>
    <div class="ann-media-grid" id="annMediaGrid"></div>
  </div>
</div>

<!-- Feedback / Success Modal -->
<div class="ann-feedback" id="annFeedback" hidden aria-hidden="true">
  <div class="ann-feedback__backdrop" data-ann-feedback-close></div>
  <div class="ann-feedback__dialog" role="dialog" aria-modal="true" aria-labelledby="annFeedbackTitle">
    <div class="ann-feedback__icon" id="annFeedbackIcon" aria-hidden="true">
      <svg class="ann-feedback__icon-svg ann-feedback__icon-svg--success" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
      <svg class="ann-feedback__icon-svg ann-feedback__icon-svg--error" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" hidden>
        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
      </svg>
      <svg class="ann-feedback__icon-svg ann-feedback__icon-svg--warn" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" hidden>
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
    </div>
    <h3 class="ann-feedback__title" id="annFeedbackTitle">Success</h3>
    <p class="ann-feedback__message" id="annFeedbackMessage"></p>
    <div class="ann-feedback__actions" id="annFeedbackActions"></div>
  </div>
</div>

<script>
window.ANN_CONFIG = {
  api: <?= json_encode($apiBase) ?>,
  mediaApi: <?= json_encode($mediaApi) ?>,
  csrf: document.body.dataset.csrf || '',
  categories: <?= json_encode($categories) ?>,
  landingUrl: <?= json_encode(ASSET_BASE . '/index.php') ?>
};
</script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-announcements.js?v=2"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
