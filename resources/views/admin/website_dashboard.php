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
require_once BASE_PATH . '/app/includes/landing_page_config.php';
require_once __DIR__ . '/_portal_access.php';

$page_title = 'Website Dashboard';
AnnouncementService::ensureSchema($pdo);

$portalBase = (defined('MC_PORTAL_SHELL') && MC_PORTAL_SHELL === 'superadmin')
    ? ASSET_BASE . '/views/superadmin'
    : ASSET_BASE . '/views/admin';
$apiBase = ASSET_BASE . '/app/api/admin/landing_page.php';
$publicLanding = ASSET_BASE . '/index.php';
$config = LandingPageConfig::all($pdo);
$stats = LandingPageConfig::dashboardStats($pdo);

require_once __DIR__ . '/partials/layout_open.php';
?>

<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/admin-landing-page.css?v=2">

<div class="lp-mgmt" id="lpMgmt">

  <div class="lp-mgmt__hero-banner">
    <div>
      <h2 class="text-h2" style="margin:0 0 6px;">Website Dashboard</h2>
      <p class="text-muted" style="margin:0;">Manage the public landing page — hero content, announcements, media, and section visibility.</p>
    </div>
    <span class="mc-badge">Landing Page CMS</span>
  </div>

  <div class="lp-mgmt__stats">
    <div class="mc-card lp-mgmt__stat">
      <div class="lp-mgmt__stat-value" id="statTotal"><?= (int) $stats['announcements_total'] ?></div>
      <div class="lp-mgmt__stat-label">Total Announcements</div>
    </div>
    <div class="mc-card lp-mgmt__stat">
      <div class="lp-mgmt__stat-value" id="statPublished"><?= (int) $stats['announcements_published'] ?></div>
      <div class="lp-mgmt__stat-label">Published</div>
    </div>
    <div class="mc-card lp-mgmt__stat">
      <div class="lp-mgmt__stat-value lp-mgmt__stat-value--muted" id="statDrafts"><?= (int) $stats['announcements_drafts'] ?></div>
      <div class="lp-mgmt__stat-label">Drafts</div>
    </div>
    <div class="mc-card lp-mgmt__stat">
      <div class="lp-mgmt__stat-value" id="statFeatured"><?= (int) $stats['announcements_featured'] ?></div>
      <div class="lp-mgmt__stat-label">Featured</div>
    </div>
    <div class="mc-card lp-mgmt__stat">
      <div class="lp-mgmt__stat-value" id="statActive"><?= (int) $stats['announcements_active'] ?></div>
      <div class="lp-mgmt__stat-label">Active on Site</div>
    </div>
    <div class="mc-card lp-mgmt__stat">
      <div class="lp-mgmt__stat-value lp-mgmt__stat-value--muted" id="statMedia"><?= (int) $stats['media_count'] ?></div>
      <div class="lp-mgmt__stat-label">Media Files</div>
    </div>
    <div class="mc-card lp-mgmt__stat">
      <div class="lp-mgmt__stat-value lp-mgmt__stat-value--muted" id="statVisits">N/A</div>
      <div class="lp-mgmt__stat-label">Homepage Visits</div>
    </div>
  </div>

  <div class="lp-mgmt__quick-actions">
    <a href="<?= htmlspecialchars($publicLanding) ?>" target="_blank" rel="noopener" class="mc-btn mc-btn--primary">Open Public Landing Page</a>
    <a href="<?= htmlspecialchars($portalBase) ?>/announcements.php" class="mc-btn mc-btn--outline">Create Announcement</a>
    <a href="<?= htmlspecialchars($portalBase) ?>/announcements.php" class="mc-btn mc-btn--outline">Manage Announcements</a>
    <a href="<?= htmlspecialchars($publicLanding) ?>?preview=1" target="_blank" rel="noopener" class="mc-btn mc-btn--outline">Preview Landing Page</a>
    <button type="button" class="mc-btn mc-btn--outline" id="lpRefreshCache">Refresh Cache</button>
  </div>

  <div class="lp-mgmt__layout">

    <div>
      <div class="lp-mgmt__cards">

        <div class="mc-card lp-mgmt__card">
          <div class="lp-mgmt__card-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
          </div>
          <h3 class="lp-mgmt__card-title">Hero Section</h3>
          <p class="lp-mgmt__card-desc">Edit the homepage headline, subheading, background image, and hero animation.</p>
          <form id="lpHeroForm" class="lp-mgmt__form-grid">
            <label>Accent heading
              <input type="text" name="hero_accent" id="heroAccent" maxlength="120" required>
            </label>
            <label>Main heading (line 1)
              <input type="text" name="hero_line1" id="heroLine1" maxlength="160" required>
            </label>
            <label>Main heading (line 2)
              <input type="text" name="hero_line2" id="heroLine2" maxlength="160" required>
            </label>
            <label>Subheading
              <textarea name="hero_subheading" id="heroSubheading" maxlength="600" required></textarea>
            </label>
            <label>Background image path
              <input type="text" name="hero_bg_image" id="heroBgImage" placeholder="assets/img/cho-hero-bg.jpg" required>
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
              <input type="checkbox" name="hero_animation" id="heroAnimation" value="1">
              Enable hero animations
            </label>
            <button type="submit" class="mc-btn mc-btn--primary">Save Hero Section</button>
          </form>
        </div>

        <div class="mc-card lp-mgmt__card">
          <div class="lp-mgmt__card-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          </div>
          <h3 class="lp-mgmt__card-title">Announcements</h3>
          <p class="lp-mgmt__card-desc">Landing announcements are synced from the database. Manage published content and drafts from the announcement module.</p>
          <div class="lp-mgmt__card-actions">
            <a href="<?= htmlspecialchars($portalBase) ?>/announcements.php" class="mc-btn mc-btn--primary">Manage Announcements</a>
            <a href="<?= htmlspecialchars($portalBase) ?>/announcements.php" class="mc-btn mc-btn--outline">Create Announcement</a>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px;">
            <div class="mc-card" style="padding:12px;text-align:center;">
              <div style="font-size:22px;font-weight:800;color:#069396;" id="statPublishedCard"><?= (int) $stats['announcements_published'] ?></div>
              <div class="text-xs text-muted">Published</div>
            </div>
            <div class="mc-card" style="padding:12px;text-align:center;">
              <div style="font-size:22px;font-weight:800;" id="statDraftsCard"><?= (int) $stats['announcements_drafts'] ?></div>
              <div class="text-xs text-muted">Drafts</div>
            </div>
          </div>
        </div>

        <div class="mc-card lp-mgmt__card">
          <div class="lp-mgmt__card-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
          </div>
          <h3 class="lp-mgmt__card-title">Gallery / Media Library</h3>
          <p class="lp-mgmt__card-desc">Upload and reuse images for announcement banners and website content. <?= (int) $stats['media_count'] ?> file(s) in library.</p>
          <div class="lp-mgmt__card-actions">
            <a href="<?= htmlspecialchars($portalBase) ?>/media_library.php" class="mc-btn mc-btn--primary">Open Media Library</a>
            <a href="<?= htmlspecialchars($portalBase) ?>/media_library.php" class="mc-btn mc-btn--outline">Upload Images</a>
          </div>
        </div>

        <div class="mc-card lp-mgmt__card">
          <div class="lp-mgmt__card-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          </div>
          <h3 class="lp-mgmt__card-title">Landing Page Settings</h3>
          <p class="lp-mgmt__card-desc">Control which sections appear on the public homepage and enable a maintenance banner.</p>
          <form id="lpSettingsForm">
            <div class="lp-mgmt__toggle-row">
              <span>Show Announcements section</span>
              <input type="checkbox" name="section_announcements" id="sectionAnnouncements" value="1">
            </div>
            <div class="lp-mgmt__toggle-row">
              <span>Show Services section</span>
              <input type="checkbox" name="section_services" id="sectionServices" value="1">
            </div>
            <div class="lp-mgmt__toggle-row">
              <span>Show How It Works section</span>
              <input type="checkbox" name="section_how_it_works" id="sectionHowItWorks" value="1">
            </div>
            <div class="lp-mgmt__toggle-row">
              <span>Show Contact section</span>
              <input type="checkbox" name="section_contact" id="sectionContact" value="1">
            </div>
            <div class="lp-mgmt__toggle-row">
              <span>Enable maintenance banner</span>
              <input type="checkbox" name="maintenance_banner" id="maintenanceBanner" value="1">
            </div>
            <label style="display:grid;gap:6px;margin-top:12px;font-size:12px;font-weight:600;">
              Maintenance message
              <textarea name="maintenance_message" id="maintenanceMessage" rows="2"></textarea>
            </label>
            <button type="submit" class="mc-btn mc-btn--primary" style="margin-top:14px;">Save Settings</button>
          </form>
        </div>

      </div>

      <div class="mc-card lp-mgmt__activity" style="padding:0;overflow:hidden;margin-top:20px;">
        <div style="padding:18px 20px;border-bottom:1px solid #e2edf1;">
          <h3 class="text-h2" style="margin:0;font-size:17px;">Recent Activity</h3>
          <p class="text-muted" style="margin:4px 0 0;font-size:13px;">Recently modified announcements</p>
        </div>
        <div style="overflow-x:auto;">
          <table class="mc-table">
            <thead>
              <tr>
                <th>Title</th>
                <th>Last modified by</th>
                <th>Date &amp; time</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="lpActivityBody">
              <tr><td colspan="4" class="mc-table-empty">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <aside class="mc-card lp-mgmt__preview">
      <div class="lp-mgmt__preview-head">
        <h3>Live Preview</h3>
        <p class="lp-mgmt__preview-meta" id="lpPreviewUpdated">
          <?php if ($stats['last_updated']): ?>
            Last updated <?= htmlspecialchars(date('M j, Y g:i A', strtotime($stats['last_updated']))) ?>
            <?= $stats['last_updated_by'] ? ' by ' . htmlspecialchars($stats['last_updated_by']) : '' ?>
          <?php else: ?>
            Not updated yet
          <?php endif; ?>
        </p>
      </div>
      <div class="lp-mgmt__preview-hero" id="lpPreviewHero" style="background-image:url('<?= htmlspecialchars(ASSET_BASE . '/' . ltrim($config['LANDING_HERO_BG_IMAGE'], '/')) ?>')">
        <span class="lp-mgmt__preview-badge">City Health Office · Bago City</span>
        <h4 class="lp-mgmt__preview-title" id="lpPreviewTitle">
          <span class="accent"><?= htmlspecialchars($config['LANDING_HERO_ACCENT']) ?></span>
          <?= htmlspecialchars($config['LANDING_HERO_LINE1']) ?><br>
          <?= htmlspecialchars($config['LANDING_HERO_LINE2']) ?>
        </h4>
        <p class="lp-mgmt__preview-sub" id="lpPreviewSub"><?= htmlspecialchars($config['LANDING_HERO_SUBHEADING']) ?></p>
      </div>
      <div class="lp-mgmt__preview-ann">
        <h4>Latest Announcements</h4>
        <div id="lpPreviewAnn"><p class="lp-mgmt__preview-ann-item">Loading…</p></div>
      </div>
    </aside>

  </div>
</div>

<div class="lp-mgmt__toast" id="lpToast" role="status" aria-live="polite"></div>

<script>
document.body.dataset.lpApi = <?= json_encode($apiBase) ?>;
document.body.dataset.publicLanding = <?= json_encode($publicLanding) ?>;
document.body.dataset.assetBase = <?= json_encode(ASSET_BASE) ?>;
</script>
<script src="<?= ASSET_BASE ?>/assets/js/admin-landing-page.js?v=2"></script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
